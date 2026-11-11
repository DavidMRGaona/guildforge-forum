<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * What awaits moderation, in the categories asked for (null: all of them): posts with open
 * reports and posts awaiting approval, oldest first.
 */
interface ModerationQueueQueryInterface
{
    /**
     * @param  list<string>|null  $categoryIds
     * @return list<ReportedPostDTO>
     */
    public function reportedPosts(?array $categoryIds, int $limit): array;

    /**
     * @param  list<string>|null  $categoryIds
     * @return list<PendingPostDTO>
     */
    public function pendingPosts(?array $categoryIds, int $limit): array;
}
