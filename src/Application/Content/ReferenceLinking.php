<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content;

use Closure;
use Modules\Forum\Application\Content\Nodes\CodeBlockNode;
use Modules\Forum\Application\Content\Nodes\PostQuoteNode;
use Modules\Forum\Application\Content\Nodes\PostReferenceNode;

/**
 * Turns «#3» in a post's text into a reference to post 3, when the number names a post the
 * caller resolves. Only a word of its own counts: not after a letter, a digit, «/» or «#», nor
 * before a letter or a digit, looking across the runs of text next to it. Code (blocks and
 * marks), links and quotations of other posts keep their text: there «#3» is not the author
 * pointing at a post.
 *
 * At most DocumentLimits::MAX_REFERENCES references are left in the document, counting the
 * ones already there; the rest stay as text. The labels the editor puts on references are
 * dropped: only the post's id is stored.
 */
final class ReferenceLinking
{
    /** A «#N» that is a word of its own inside a run; its edges are checked against the runs next to it */
    private const string NUMBER = '/(?<![\p{L}\p{N}\/#])#([1-9]\d{0,9})(?![\p{L}\p{N}])/u';

    /** Text with these marks keeps its words */
    private const array LITERAL_MARKS = ['code', 'link'];

    /**
     * @param  array<string, mixed>  $document  a document that passed the schema
     * @param  Closure(list<int>): array<int, string>  $resolve  the ids of the posts with these numbers that may be referenced
     * @return array<string, mixed>
     */
    public static function link(array $document, Closure $resolve): array
    {
        $numbers = [];
        $existing = 0;
        self::collect($document, $numbers, $existing);

        $ids = $numbers === [] ? [] : $resolve(array_map(intval(...), array_keys($numbers)));
        $room = max(0, DocumentLimits::MAX_REFERENCES - $existing);

        return self::rewrite($document, $ids, $room);
    }

    /**
     * The numbers written as words of their own, and how many references there already are.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<int, true>  $numbers
     */
    private static function collect(array $node, array &$numbers, int &$existing): void
    {
        $type = $node['type'] ?? null;
        if ($type === (new PostReferenceNode)->name()) {
            $existing++;

            return;
        }
        if (self::keepsItsText($type)) {
            return;
        }

        $children = self::children($node);
        foreach ($children as $index => $child) {
            // Only as many numbers as may become references are looked up
            if (count($numbers) >= DocumentLimits::MAX_REFERENCES) {
                return;
            }
            if (self::isConvertibleText($child)) {
                foreach (self::matches($children, $index) as [, , $number]) {
                    if (count($numbers) >= DocumentLimits::MAX_REFERENCES) {
                        break;
                    }
                    $numbers[$number] = true;
                }
            } else {
                self::collect($child, $numbers, $existing);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<int, string>  $ids
     * @return array<array-key, mixed>
     */
    private static function rewrite(array $node, array $ids, int &$room): array
    {
        $type = $node['type'] ?? null;
        if ($type === (new PostReferenceNode)->name()) {
            $attributes = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            unset($attributes['label']);

            return [...$node, 'attrs' => $attributes];
        }
        if (self::keepsItsText($type) || ! is_array($node['content'] ?? null)) {
            return $node;
        }

        $children = self::children($node);
        $content = [];
        foreach ($children as $index => $child) {
            if (! self::isConvertibleText($child)) {
                $content[] = self::rewrite($child, $ids, $room);

                continue;
            }

            $text = is_string($child['text'] ?? null) ? $child['text'] : '';
            $offset = 0;
            foreach (self::matches($children, $index) as [$start, $length, $number]) {
                if ($room === 0 || ! isset($ids[$number])) {
                    continue;
                }
                $content = [...$content, ...self::piece($child, substr($text, $offset, $start - $offset))];
                $content[] = ['type' => (new PostReferenceNode)->name(), 'attrs' => ['postId' => $ids[$number]]];
                $offset = $start + $length;
                $room--;
            }
            $content = [...$content, ...self::piece($child, substr($text, $offset))];
        }

        return [...$node, 'content' => $content];
    }

    /**
     * Each «#N» of a text run that is a word of its own: byte offset, byte length and number.
     *
     * @param  list<array<array-key, mixed>>  $siblings
     * @return list<array{int, int, int}>
     */
    private static function matches(array $siblings, int $index): array
    {
        $text = is_string($siblings[$index]['text'] ?? null) ? $siblings[$index]['text'] : '';
        if (preg_match_all(self::NUMBER, $text, $found, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        // Inside the run the pattern already looked around each number; only a number at the
        // run's start or end is checked against the run next to it
        $matches = [];
        $length = strlen($text);
        foreach ($found[0] as $i => [$whole, $start]) {
            $end = $start + strlen($whole);
            if ($start === 0 && preg_match('/[\p{L}\p{N}\/#]/u', self::lastCharacterOf($siblings[$index - 1] ?? null)) === 1) {
                continue;
            }
            if ($end === $length && preg_match('/[\p{L}\p{N}]/u', self::firstCharacterOf($siblings[$index + 1] ?? null)) === 1) {
                continue;
            }
            $matches[] = [$start, strlen($whole), (int) $found[1][$i][0]];
        }

        return $matches;
    }

    /**
     * The part of a text run, with its marks; nothing when it is empty (the schema refuses empty text).
     *
     * @param  array<array-key, mixed>  $run
     * @return list<array<array-key, mixed>>
     */
    private static function piece(array $run, string $text): array
    {
        return $text === '' ? [] : [[...$run, 'text' => $text]];
    }

    private static function keepsItsText(mixed $type): bool
    {
        return $type === (new CodeBlockNode)->name() || $type === (new PostQuoteNode)->name();
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function isConvertibleText(array $node): bool
    {
        if (($node['type'] ?? null) !== 'text' || ! is_string($node['text'] ?? null)) {
            return false;
        }

        return array_all(is_array($node['marks'] ?? null) ? $node['marks'] : [], fn ($mark) => ! is_array($mark) || ! in_array($mark['type'] ?? null, self::LITERAL_MARKS, true));
    }

    /**
     * The character a neighbouring node ends or starts with: only text has one (any other node,
     * or none, is a boundary).
     *
     * @param  array<array-key, mixed>|null  $node
     */
    private static function lastCharacterOf(mixed $node): string
    {
        return is_array($node) && ($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null) ? mb_substr($node['text'], -1) : '';
    }

    /**
     * @param  array<array-key, mixed>|null  $node
     */
    private static function firstCharacterOf(mixed $node): string
    {
        return is_array($node) && ($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null) ? mb_substr($node['text'], 0, 1) : '';
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return list<array<array-key, mixed>>
     */
    private static function children(array $node): array
    {
        $children = [];
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                $children[] = $child;
            }
        }

        return $children;
    }
}
