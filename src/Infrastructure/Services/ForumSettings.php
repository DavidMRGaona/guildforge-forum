<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Modules\Forum\Application\Contracts\ForumSettingsInterface;

/**
 * The module settings: release defaults (config/settings.php) with what the panel saved on top.
 * A stored value out of bounds is brought back within them, and one that is not a number gives
 * the default.
 */
final readonly class ForumSettings implements ForumSettingsInterface
{
    public const int DEFAULT_PAGE_SIZE = 20;

    public const int MIN_PAGE_SIZE = 10;

    public const int MAX_PAGE_SIZE = 100;

    public const int DEFAULT_POSTS_PER_MINUTE = 6;

    public const int MIN_POSTS_PER_MINUTE = 1;

    public const int MAX_POSTS_PER_MINUTE = 60;

    public function discussionsPerPage(): int
    {
        return $this->pageSize('discussions_per_page');
    }

    public function postsPerPage(): int
    {
        return $this->pageSize('posts_per_page');
    }

    public function postsPerMinute(): int
    {
        return $this->bounded('posts_per_minute', self::MIN_POSTS_PER_MINUTE, self::MAX_POSTS_PER_MINUTE, self::DEFAULT_POSTS_PER_MINUTE);
    }

    private function pageSize(string $key): int
    {
        return $this->bounded($key, self::MIN_PAGE_SIZE, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE);
    }

    private function bounded(string $key, int $min, int $max, int $default): int
    {
        $value = config("modules.settings.forum.{$key}");

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}
