<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use DateTimeImmutable;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\MergedDiscussion;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Where moderation sent posts and discussions. Rows are only added (and pointed further on when
 * a discussion they lead to is merged again), so an old address always leads somewhere and the
 * numbers they hold are never given out again.
 */
interface RedirectRepositoryInterface
{
    /**
     * The posts that left the discussion, each under the number it had there.
     *
     * @param  array<int, PostId>  $postsByNumber
     */
    public function recordMovedPosts(DiscussionId $from, array $postsByNumber, DateTimeImmutable $at): void;

    /**
     * The discussion (under its public number) was merged into another. Whatever led to it
     * leads to that one from now on.
     */
    public function recordMergedDiscussion(int $fromNumber, DiscussionId $from, DiscussionId $into, DateTimeImmutable $at): void;

    /**
     * The highest number of a post that left the discussion; null when none did.
     */
    public function highestMovedNumber(DiscussionId $from): ?int;

    /**
     * The post that had the number in the discussion and left it; null when none did, or once
     * that post is gone.
     */
    public function movedPost(DiscussionId $from, int $number): ?PostId;

    /**
     * The discussion that had the public number and was merged into another; null when none was.
     */
    public function mergedDiscussion(int $number): ?MergedDiscussion;
}
