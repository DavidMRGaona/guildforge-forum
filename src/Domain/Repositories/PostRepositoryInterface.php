<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use DateTimeImmutable;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PostNumber;

interface PostRepositoryInterface
{
    public function save(Post $post): void;

    public function find(PostId $id): ?Post;

    /**
     * @throws PostNotFoundException
     */
    public function findOrFail(PostId $id): Post;

    /**
     * The posts that exist among the ids, in no particular order.
     *
     * @param  list<PostId>  $ids
     * @return list<Post>
     */
    public function findMany(array $ids): array;

    /**
     * The highest number given out in the discussion, whatever the post's status now; null
     * when none has one yet.
     */
    public function highestNumber(DiscussionId $discussionId): ?PostNumber;

    /**
     * Every post of the discussion: numbered ones in their order, then those awaiting approval
     * by when they were written.
     *
     * @return list<Post>
     */
    public function inDiscussion(DiscussionId $discussionId): array;

    /**
     * The numbered posts of the discussion from the number on, in order, whatever their status.
     *
     * @return list<Post>
     */
    public function numberedFrom(DiscussionId $discussionId, PostNumber $from, int $limit): array;

    /**
     * The numbered posts of the discussion whose numbers lie in the range (both ends included),
     * in order, whatever their status: one page of it, which holds fewer posts where some left.
     *
     * @return list<Post>
     */
    public function numberedBetween(DiscussionId $discussionId, PostNumber $from, PostNumber $through): array;

    /**
     * The lowest number of a numbered post of the discussion in the range (both ends included);
     * null when every number of it moved away.
     */
    public function firstNumberBetween(DiscussionId $discussionId, PostNumber $from, PostNumber $through): ?PostNumber;

    /**
     * The numbered posts of the discussion that have these numbers, in order; numbers it does
     * not have are left out.
     *
     * @param  list<int>  $numbers
     * @return list<Post>
     */
    public function withNumbers(DiscussionId $discussionId, array $numbers): array;

    /**
     * The last `limit` numbered posts of the discussion from `from` on and below `before` (to
     * the end when null), in order, whatever their status: a conversation read from its end.
     *
     * @return list<Post>
     */
    public function lastNumbered(DiscussionId $discussionId, PostNumber $from, ?PostNumber $before, int $limit): array;

    /**
     * The discussion's posts awaiting approval, by when they were written.
     *
     * @return list<Post>
     */
    public function pendingIn(DiscussionId $discussionId): array;

    /**
     * Posts their authors deleted before the given time whose content is still kept, oldest
     * deletion first.
     *
     * @return list<PostId>
     */
    public function deletedAwaitingPurge(DateTimeImmutable $deletedBefore, int $limit): array;

    /**
     * How many posts the account has published in the forum that are still up (shown, or
     * deleted by themselves; never the ones moderation hid or that await approval), counted up
     * to the limit: past it the exact number does not matter.
     */
    public function countPublishedBy(string $authorId, int $limit): int;

    public function delete(PostId $id): void;
}
