<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * What changed in the forum, asked while pages are open, so every answer reads indexed rows and
 * never recalculates a counter. The category ids are the ones the reader may read (decided by
 * the access rules before asking); hidden discussions only show in the moderated ones.
 */
interface ForumActivityQueryInterface
{
    /**
     * Ids of discussions with posts the reader has not read, among those they follow (the
     * discussions themselves, or the categories in `followedCategoryIds`), at most `$limit`.
     *
     * @param  list<string>  $categoryIds  readable
     * @param  list<string>  $moderatedCategoryIds
     * @param  list<string>  $followedCategoryIds
     * @return list<string>
     */
    public function unreadFollowedIds(string $readerId, array $categoryIds, array $moderatedCategoryIds, array $followedCategoryIds, int $limit): array;

    /**
     * The number of the last post of each discussion given, for those the reader may see (the
     * others are left out, as if they did not exist).
     *
     * @param  list<string>  $discussionIds
     * @param  list<string>  $categoryIds  readable
     * @param  list<string>  $moderatedCategoryIds
     * @return array<string, int> by discussion id
     */
    public function lastPostNumbers(array $discussionIds, array $categoryIds, array $moderatedCategoryIds): array;
}
