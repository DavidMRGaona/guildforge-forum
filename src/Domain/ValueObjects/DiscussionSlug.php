<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

/**
 * The words of a discussion's title in its address ("mesa-del-viernes"), only there for people
 * and search engines: the discussion is found by its number, and a stale slug redirects.
 */
final readonly class DiscussionSlug
{
    public const int MAX_LENGTH = 60;

    public static function of(string $title): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $title);
        $words = preg_split('/[^a-z0-9]+/', is_string($ascii) ? $ascii : '', -1, PREG_SPLIT_NO_EMPTY);

        $slug = '';
        foreach ($words === false ? [] : $words as $word) {
            $next = $slug === '' ? $word : "{$slug}-{$word}";
            if (strlen($next) > self::MAX_LENGTH) {
                // Cut at a word, unless the first word alone is too long
                return $slug === '' ? substr($word, 0, self::MAX_LENGTH) : $slug;
            }

            $slug = $next;
        }

        return $slug;
    }

    /**
     * The address segment: "42-mesa-del-viernes", or "42" for a title with no letters or digits.
     */
    public static function segment(int $number, string $title): string
    {
        $slug = self::of($title);

        return $slug === '' ? (string) $number : "{$number}-{$slug}";
    }
}
