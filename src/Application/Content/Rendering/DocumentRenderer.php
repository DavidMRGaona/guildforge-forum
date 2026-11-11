<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Rendering;

use Modules\Forum\Application\Content\Contracts\MarkTypeInterface;
use Modules\Forum\Application\Content\Contracts\NodeTypeInterface;
use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Application\Content\DocumentSchema;

/**
 * Turns a post document into HTML or plain text with the schema's own node and mark types.
 * It knows nothing else: a node or mark the schema does not have (a stored document from a
 * feature since removed) is left out, never passed through, and all text is escaped by the
 * types themselves. Documents are checked when stored; this walk still guards against a stored
 * one that is malformed.
 */
final readonly class DocumentRenderer
{
    public function __construct(
        private DocumentSchema $schema,
    ) {}

    /**
     * @param  array<array-key, mixed>  $document
     */
    public function toHtml(array $document, RenderContext $context): string
    {
        return implode('', $this->children($document, $context, html: true, depth: 1));
    }

    /**
     * Plain text: blocks one per line, list items with their marker, quotations after "> ",
     * mentions with the current name and images by their description. Never more than one
     * blank line in a row.
     *
     * @param  array<array-key, mixed>  $document
     */
    public function toText(array $document, RenderContext $context): string
    {
        $text = implode("\n", $this->children($document, $context, html: false, depth: 1));
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /**
     * The attributes of every node of the given types in a stored document, by type, without
     * checking it (to look up mentioned accounts and quoted posts before rendering).
     *
     * @param  array<array-key, mixed>  $document
     * @param  list<string>  $types
     * @return array<string, list<array<string, mixed>>>
     */
    public function attributesOf(array $document, array $types): array
    {
        $found = [];
        $this->collect($document, $types, $found, 1);

        return $found;
    }

    /**
     * Every node of a type, whole (attributes and content), at any depth.
     *
     * @param  array<array-key, mixed>  $document
     * @return list<array<array-key, mixed>>
     */
    public function nodesOf(array $document, string $type): array
    {
        $found = [];
        $walk = static function (array $node, int $depth) use (&$walk, &$found, $type): void {
            $content = $node['content'] ?? null;
            if (! is_array($content) || $depth > DocumentLimits::MAX_DEPTH) {
                return;
            }

            foreach ($content as $child) {
                if (is_array($child)) {
                    if (($child['type'] ?? null) === $type) {
                        $found[] = $child;
                    }
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($document, 1);

        return $found;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $types
     * @param  array<string, list<array<string, mixed>>>  $found
     */
    private function collect(array $node, array $types, array &$found, int $depth): void
    {
        $content = $node['content'] ?? null;
        if (! is_array($content) || $depth > DocumentLimits::MAX_DEPTH) {
            return;
        }

        foreach ($content as $child) {
            if (! is_array($child)) {
                continue;
            }

            $type = $child['type'] ?? null;
            if (is_string($type) && in_array($type, $types, true) && is_array($child['attrs'] ?? null)) {
                /** @var array<string, mixed> $attributes */
                $attributes = $child['attrs'];
                $found[$type][] = $attributes;
            }

            $this->collect($child, $types, $found, $depth + 1);
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    private function children(array $node, RenderContext $context, bool $html, int $depth): array
    {
        $content = $node['content'] ?? null;
        if (! is_array($content) || $depth > DocumentLimits::MAX_DEPTH) {
            return [];
        }

        $rendered = [];
        foreach ($content as $child) {
            if (is_array($child)) {
                $rendered[] = $this->node($child, $context, $html, $depth + 1);
            }
        }

        return $rendered;
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private function node(array $node, RenderContext $context, bool $html, int $depth): string
    {
        $type = $node['type'] ?? null;

        if ($type === DocumentSchema::TEXT) {
            return $this->text($node, $html);
        }

        $nodeType = is_string($type) ? $this->schema->node($type) : null;
        if (! $nodeType instanceof NodeTypeInterface) {
            return '';
        }

        $attributes = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        /** @var array<string, mixed> $attributes */
        $children = $nodeType->content() === null ? [] : $this->children($node, $context, $html, $depth);
        $rendered = $html
            ? $nodeType->toHtml($attributes, $children, $context)
            : $nodeType->toText($attributes, $children, $context);

        return $html ? $this->wrapInMarks($node, $rendered) : $rendered;
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private function text(array $node, bool $html): string
    {
        $text = $node['text'] ?? null;
        if (! is_string($text)) {
            return '';
        }

        return $html ? $this->wrapInMarks($node, Html::escape($text)) : $text;
    }

    /**
     * The first mark is the outermost, as ProseMirror orders them.
     *
     * @param  array<array-key, mixed>  $node
     */
    private function wrapInMarks(array $node, string $html): string
    {
        $marks = $node['marks'] ?? null;
        if (! is_array($marks)) {
            return $html;
        }

        foreach (array_reverse($marks) as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            $markType = is_string($type) ? $this->schema->mark($type) : null;
            if (! $markType instanceof MarkTypeInterface) {
                continue;
            }

            $attributes = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];
            /** @var array<string, mixed> $attributes */
            $html = $markType->wrapHtml($attributes, $html);
        }

        return $html;
    }
}
