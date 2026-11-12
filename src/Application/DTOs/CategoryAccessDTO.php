<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\Entities\Category;

/**
 * A category the reader may read, with what else they may do in it. One that is not available is
 * read only by the forum's moderators, because a module it depends on is missing.
 */
final readonly class CategoryAccessDTO
{
    public function __construct(
        public Category $category,
        public bool $canWrite,
        public bool $canModerate,
        public bool $guestsRead,
        public bool $available = true,
    ) {}

    /**
     * Whether people may start discussions in it from the forum: the categories of game
     * tables, campaigns and events only hold the discussions their publications open, and nothing
     * new goes where only the forum's moderators still see (a module it depends on is missing).
     */
    public function canStartDiscussions(): bool
    {
        return $this->canWrite && $this->available && ! $this->category->isSystem();
    }
}
