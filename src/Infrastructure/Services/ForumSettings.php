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

    public function discussionsPerPage(): int
    {
        return $this->pageSize('discussions_per_page');
    }

    public function postsPerPage(): int
    {
        return $this->pageSize('posts_per_page');
    }

    private function pageSize(string $key): int
    {
        $value = config("modules.settings.forum.{$key}");

        return is_numeric($value)
            ? max(self::MIN_PAGE_SIZE, min(self::MAX_PAGE_SIZE, (int) $value))
            : self::DEFAULT_PAGE_SIZE;
    }
}
