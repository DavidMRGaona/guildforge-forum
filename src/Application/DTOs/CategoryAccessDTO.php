<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\Entities\Category;

/**
 * A category the reader may read, with what else they may do in it.
 */
final readonly class CategoryAccessDTO
{
    public function __construct(
        public Category $category,
        public bool $canWrite,
        public bool $canModerate,
        public bool $guestsRead,
    ) {}

    /**
     * Whether people may start discussions in it from the forum: the categories of game
     * tables, campaigns and events only hold the discussions their publications open.
     */
    public function canStartDiscussions(): bool
    {
        return $this->canWrite && ! $this->category->isSystem();
    }
}
