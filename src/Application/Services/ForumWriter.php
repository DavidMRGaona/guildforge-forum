<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ForumCountersInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PostNumber;
use RuntimeException;

/**
 * The only way discussions and posts are written. Each write runs in one transaction that locks
 * the categories involved (in id order) and then the discussion, gives out post numbers under
 * that lock and refreshes the counters before committing, so two people replying at once never
 * share a number or leave a count behind.
 *
 * Who may write what is decided before calling it (the forum's access rules); this class only
 * keeps the records consistent.
 */
final readonly class ForumWriter
{
    /** Deadlocks are retried by the transaction this many times */
    private const int ATTEMPTS = 3;

    public function __construct(
        private CategoryRepositoryInterface $categories,
        private DiscussionRepositoryInterface $discussions,
        private PostRepositoryInterface $posts,
        private ForumCountersInterface $counters,
    ) {}

    /**
     * Saves a new discussion with its first post, number 1 unless it awaits approval. The post
     * is dated when the discussion started.
     */
    public function startDiscussion(Discussion $discussion, PostId $firstPostId, PostBody $body, bool $needsApproval): Post
    {
        return DB::transaction(function () use ($discussion, $firstPostId, $body, $needsApproval): Post {
            $this->categories->lockForWrite($discussion->categoryId());
            $this->discussions->save($discussion);

            $post = $needsApproval
                ? Post::submitForApproval($firstPostId, $discussion->id(), $discussion->authorId(), $body, $discussion->createdAt())
                : Post::publish($firstPostId, $discussion->id(), PostNumber::first(), $discussion->authorId(), $body, $discussion->createdAt());
            $this->posts->save($post);

            $this->refresh($discussion->id(), [$discussion->categoryId()]);

            return $post;
        }, self::ATTEMPTS);
    }

    /**
     * Adds a post to the discussion with the next number, or without one while it awaits
     * approval.
     *
     * @throws DiscussionNotFoundException
     */
    public function reply(DiscussionId $discussionId, PostId $postId, ?string $authorId, PostBody $body, DateTimeImmutable $at, bool $needsApproval): Post
    {
        return DB::transaction(function () use ($discussionId, $postId, $authorId, $body, $at, $needsApproval): Post {
            $discussion = $this->lockDiscussion($discussionId);

            $post = $needsApproval
                ? Post::submitForApproval($postId, $discussionId, $authorId, $body, $at)
                : Post::publish($postId, $discussionId, $this->nextNumber($discussionId), $authorId, $body, $at);
            $this->posts->save($post);

            $this->refresh($discussionId, [$discussion->categoryId()]);

            return $post;
        }, self::ATTEMPTS);
    }

    /**
     * Publishes a post that awaited approval with the next number at the time of approval, so
     * it reads after whatever was published while it waited and counts as new activity.
     *
     * @throws PostNotFoundException
     * @throws InvalidPostException unless it awaits approval
     */
    public function approvePost(PostId $postId, DateTimeImmutable $at): Post
    {
        return DB::transaction(function () use ($postId, $at): Post {
            $discussion = $this->lockDiscussion($this->posts->findOrFail($postId)->discussionId());

            $post = $this->posts->findOrFail($postId);
            $post->approve($this->nextNumber($discussion->id()), $at);
            $this->posts->save($post);

            $this->refresh($discussion->id(), [$discussion->categoryId()]);

            return $post;
        }, self::ATTEMPTS);
    }

    /**
     * Saves a change to a post already written (an edit, hiding, restoring, its author deleting
     * it). Approving goes through approvePost(), which gives out the number.
     *
     * @throws DiscussionNotFoundException
     */
    public function savePost(Post $post): void
    {
        DB::transaction(function () use ($post): void {
            $discussion = $this->lockDiscussion($post->discussionId());

            $this->posts->save($post);

            $this->refresh($discussion->id(), [$discussion->categoryId()]);
        }, self::ATTEMPTS);
    }

    /**
     * Removes a post that never got a number (a moderator rejected it or its author withdrew
     * it). A numbered post is hidden or deleted instead: removing it would leave a gap.
     *
     * @throws PostNotFoundException
     * @throws InvalidPostException when the post has a number
     */
    public function discardPendingPost(PostId $postId): void
    {
        DB::transaction(function () use ($postId): void {
            $discussion = $this->lockDiscussion($this->posts->findOrFail($postId)->discussionId());

            $post = $this->posts->findOrFail($postId);
            if ($post->status() !== PostStatus::Pending) {
                throw InvalidPostException::notPending($postId->value);
            }

            $this->posts->delete($postId);

            $this->refresh($discussion->id(), [$discussion->categoryId()]);
        }, self::ATTEMPTS);
    }

    /**
     * Saves a change to a discussion (title, state, category). Moving it refreshes the
     * category it leaves and the one it joins.
     *
     * @throws DiscussionNotFoundException
     */
    public function saveDiscussion(Discussion $discussion): void
    {
        DB::transaction(function () use ($discussion): void {
            $stored = $this->discussions->findOrFail($discussion->id());
            $categories = $this->lockCategories([$stored->categoryId(), $discussion->categoryId()]);
            $locked = $this->discussions->findForUpdate($discussion->id());

            // Moved by someone else since it was read: that category changes too
            if (! $locked->categoryId()->equals($stored->categoryId())) {
                $this->categories->lockForWrite($locked->categoryId());
                $categories[] = $locked->categoryId();
            }

            $this->discussions->save($discussion);

            $this->refresh($discussion->id(), $categories);
        }, self::ATTEMPTS);
    }

    /**
     * Deletes the discussion with every post in it.
     *
     * @throws DiscussionNotFoundException
     */
    public function deleteDiscussion(DiscussionId $discussionId): void
    {
        DB::transaction(function () use ($discussionId): void {
            $discussion = $this->lockDiscussion($discussionId);

            $this->discussions->delete($discussionId);

            $this->counters->refreshCategory($discussion->categoryId());
        }, self::ATTEMPTS);
    }

    /**
     * Moves every discussion of one category into another (before deleting it), refreshing both.
     */
    public function moveAllDiscussions(CategoryId $from, CategoryId $to): void
    {
        DB::transaction(function () use ($from, $to): void {
            $categories = $this->lockCategories([$from, $to]);

            $this->discussions->moveAllToCategory($from, $to);

            foreach ($categories as $categoryId) {
                $this->counters->refreshCategory($categoryId);
            }
        }, self::ATTEMPTS);
    }

    /**
     * Locks the discussion's category and then the discussion. Should another write move it
     * between reading its category and locking it, the new category is locked too.
     *
     * @throws DiscussionNotFoundException
     */
    private function lockDiscussion(DiscussionId $discussionId): Discussion
    {
        $categoryId = $this->discussions->findOrFail($discussionId)->categoryId();

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $this->categories->lockForWrite($categoryId);
            $discussion = $this->discussions->findForUpdate($discussionId);

            if ($discussion->categoryId()->equals($categoryId)) {
                return $discussion;
            }

            $categoryId = $discussion->categoryId();
        }

        throw new RuntimeException("Discussion {$discussionId->value} kept moving between categories while it was being written.");
    }

    /**
     * @param  list<CategoryId>  $ids
     * @return list<CategoryId> the distinct ids, locked in id order so two writes never wait on each other
     */
    private function lockCategories(array $ids): array
    {
        $distinct = [];
        foreach ($ids as $id) {
            $distinct[$id->value] = $id;
        }
        ksort($distinct, SORT_STRING);

        foreach ($distinct as $id) {
            $this->categories->lockForWrite($id);
        }

        return array_values($distinct);
    }

    private function nextNumber(DiscussionId $discussionId): PostNumber
    {
        return PostNumber::after($this->posts->highestNumber($discussionId));
    }

    /**
     * @param  list<CategoryId>  $categoryIds
     */
    private function refresh(DiscussionId $discussionId, array $categoryIds): void
    {
        $this->counters->refreshDiscussion($discussionId);

        foreach ($categoryIds as $categoryId) {
            $this->counters->refreshCategory($categoryId);
        }
    }
}
