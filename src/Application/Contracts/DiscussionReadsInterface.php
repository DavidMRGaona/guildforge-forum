<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use DateTimeImmutable;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * How far each person read the forum: per discussion, the highest post number they saw (it only
 * moves forward); per category, when they marked it as read (everything published in it before
 * then counts as read).
 */
interface DiscussionReadsInterface
{
    /**
     * The number up to which the person has read the discussion, by their mark in it or their
     * category's: 0 when they read none of it.
     */
    public function readThrough(string $accountId, DiscussionId $discussionId, CategoryId $categoryId): int;

    /**
     * Moves the person's mark in the discussion up to the number, if it is behind it.
     */
    public function advance(string $accountId, DiscussionId $discussionId, int $number, DateTimeImmutable $at): void;

    /**
     * Marks the categories as read at the time: what was published in them until then counts
     * as read.
     *
     * @param  list<CategoryId>  $categoryIds
     */
    public function markCategoriesRead(string $accountId, array $categoryIds, DateTimeImmutable $at): void;
}
