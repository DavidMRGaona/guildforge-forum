<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ForumCountersInterface;
use Modules\Forum\Application\Contracts\ForumNoticesInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Entities\PostRevision;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRevisionRepositoryInterface;
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
 * keeps the records consistent. Every post that gets its number (and every edit of a published
 * post) is handed to the notices, which run once the write commits.
 */
final readonly class ForumWriter
{
    /** Deadlocks are retried by the transaction this many times */
    private const int ATTEMPTS = 3;

    /** Starting a discussion is retried this many times when its public number was taken */
    private const int NUMBER_ATTEMPTS = 5;

    public function __construct(
        private CategoryRepositoryInterface $categories,
        private DiscussionRepositoryInterface $discussions,
        private PostRepositoryInterface $posts,
        private ForumCountersInterface $counters,
        private PostRevisionRepositoryInterface $revisions,
        private ForumNoticesInterface $notices,
    ) {}

    /**
     * Saves a new discussion with its first post, number 1 unless it awaits approval (then the
     * discussion waits with it: only its author and moderators see it). The post is dated when
     * the discussion started. The discussion gets the next public number; should
     * another discussion take it at the same moment, the whole write is tried again with the
     * following one.
     */
    public function startDiscussion(Discussion $discussion, PostId $firstPostId, PostBody $body, bool $needsApproval): Post
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $post = DB::transaction(function () use ($discussion, $firstPostId, $body, $needsApproval): Post {
                    $this->categories->lockForWrite($discussion->categoryId());

                    // Numbered on a copy: a failed attempt leaves the caller's discussion as it was
                    $numbered = clone $discussion;
                    if ($needsApproval) {
                        $numbered->awaitApproval();
                    }
                    if ($numbered->number() === null) {
                        $numbered->numberAs($this->discussions->nextNumber());
                    }
                    $this->discussions->save($numbered);
                    $discussion->numberAs((int) $numbered->number());
                    if ($needsApproval) {
                        $discussion->awaitApproval();
                    }

                    $post = $needsApproval
                        ? Post::submitForApproval($firstPostId, $discussion->id(), $discussion->authorId(), $body, $discussion->createdAt())
                        : Post::publish($firstPostId, $discussion->id(), PostNumber::first(), $discussion->authorId(), $body, $discussion->createdAt());
                    $this->posts->save($post);

                    $this->refresh($discussion->id(), [$discussion->categoryId()]);

                    return $post;
                }, self::ATTEMPTS);

                return $this->announced($post);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::NUMBER_ATTEMPTS || $this->discussions->find($discussion->id()) instanceof Discussion) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Adds a post to the discussion with the next number, or without one while it awaits
     * approval.
     *
     * @throws DiscussionNotFoundException
     */
    public function reply(DiscussionId $discussionId, PostId $postId, ?string $authorId, PostBody $body, DateTimeImmutable $at, bool $needsApproval): Post
    {
        return $this->announced(DB::transaction(function () use ($discussionId, $postId, $authorId, $body, $at, $needsApproval): Post {
            $discussion = $this->lockDiscussion($discussionId);

            $post = $needsApproval
                ? Post::submitForApproval($postId, $discussionId, $authorId, $body, $at)
                : Post::publish($postId, $discussionId, $this->nextNumber($discussionId), $authorId, $body, $at);
            $this->posts->save($post);

            $this->refresh($discussionId, [$discussion->categoryId()]);

            return $post;
        }, self::ATTEMPTS));
    }

    /**
     * Publishes a post that awaited approval with the next number at the time of approval, so
     * it reads after whatever was published while it waited and counts as new activity. In a
     * discussion that waited for its first post, the discussion is approved with it.
     *
     * @throws PostNotFoundException
     * @throws InvalidPostException unless it awaits approval
     */
    public function approvePost(PostId $postId, DateTimeImmutable $at): Post
    {
        return $this->announced(DB::transaction(function () use ($postId, $at): Post {
            $discussion = $this->lockDiscussion($this->posts->findOrFail($postId)->discussionId());

            $post = $this->posts->findOrFail($postId);
            $post->approve($this->nextNumber($discussion->id()), $at);
            $this->posts->save($post);

            if ($discussion->isAwaitingApproval()) {
                $discussion->approve();
                $this->discussions->save($discussion);
            }

            $this->refresh($discussion->id(), [$discussion->categoryId()]);

            return $post;
        }, self::ATTEMPTS);
    }

    /**
     * Changes a post as it is under the lock: the change runs on the post read again inside the
     * transaction, so two moderators at once never undo each other.
     *
     * @param  Closure(Post): void  $change
     *
     * @throws PostNotFoundException
     */
    public function changePost(PostId $postId, Closure $change): Post
    {
        return DB::transaction(function () use ($postId, $change): Post {
            $discussion = $this->lockDiscussion($this->posts->findOrFail($postId)->discussionId());

            $post = $this->posts->findOrFail($postId);
            $change($post);
            $this->posts->save($post);

            $this->refresh($discussion->id(), [$discussion->categoryId()]);

            return $post;
        }, self::ATTEMPTS));
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
     * Replaces what a post says, first keeping what it said as its next revision. The post is
     * read again under the lock, so two edits at once keep both versions. A body that holds the
     * same document changes nothing.
     *
     * @param  string|null  $editorId  who edits it: its author, or a moderator
     *
     * @throws PostNotFoundException
     * @throws InvalidPostException once its author deleted it
     */
    public function editPost(PostId $postId, PostBody $body, ?string $editorId, DateTimeImmutable $at): Post
    {
        $replaced = null;
        $post = DB::transaction(function () use ($postId, $body, $editorId, $at, &$replaced): Post {
            $this->lockDiscussion($this->posts->findOrFail($postId)->discussionId());

            $post = $this->posts->findOrFail($postId);
            if ($post->body()->sameDocumentAs($body)) {
                return $post;
            }

            $replaced = $post->body();
            $post->edit($body, $at);

            $this->revisions->save(new PostRevision($postId, $this->revisions->nextNumber($postId), $replaced, $editorId, $at));
            $this->posts->save($post);

            return $post;
        }, self::ATTEMPTS);

        // Only who the edit newly mentions or quotes hears about it
        if ($replaced instanceof PostBody && $post->isVisible()) {
            $this->notices->postEdited($postId, $replaced);
        }

        return $post;
    }

    /**
     * Deletes a post on its author's behalf: it keeps its number, and its body and revisions
     * stay for moderators until they are purged. Readers see a placeholder in its place.
     *
     * @throws PostNotFoundException
     * @throws InvalidPostException unless it is visible or hidden
     */
    public function deletePostByAuthor(PostId $postId, DateTimeImmutable $at): Post
    {
        return DB::transaction(function () use ($postId, $at): Post {
            $discussion = $this->lockDiscussion($this->posts->findOrFail($postId)->discussionId());

            $post = $this->posts->findOrFail($postId);
            $post->deleteByAuthor($at);
            $this->posts->save($post);

            $this->refresh($discussion->id(), [$discussion->categoryId()]);

            return $post;
        }, self::ATTEMPTS);
    }

    /**
     * Empties a post its author deleted and drops its revisions, so nothing it said is kept
     * (nor counts as using an image). Only its place in the numbering is left.
     *
     * @throws PostNotFoundException
     * @throws InvalidPostException unless its author deleted it
     */
    public function purgeDeletedPost(PostId $postId, DateTimeImmutable $at): Post
    {
        return DB::transaction(function () use ($postId, $at): Post {
            $this->lockDiscussion($this->posts->findOrFail($postId)->discussionId());

            $post = $this->posts->findOrFail($postId);
            $post->purgeContent($at);
            $this->revisions->deleteOfPost($postId);
            $this->posts->save($post);

            return $post;
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
     * Changes a discussion as it is under the lock (pinning, closing, renaming, moving…): the
     * change runs on the discussion read again inside the transaction, so two moderators at once
     * never undo each other. A move needs the category it goes to, locked with the one it leaves
     * (in id order); both are refreshed.
     *
     * @param  Closure(Discussion): void  $change
     *
     * @throws DiscussionNotFoundException
     */
    public function changeDiscussion(DiscussionId $discussionId, Closure $change, ?CategoryId $moveTo = null): Discussion
    {
        return DB::transaction(function () use ($discussionId, $change, $moveTo): Discussion {
            $stored = $this->discussions->findOrFail($discussionId);
            $categories = $this->lockCategories(array_values(array_filter([$stored->categoryId(), $moveTo])));
            $discussion = $this->discussions->findForUpdate($discussionId);

            // Moved by someone else since it was read: that category changes too
            if (! in_array($discussion->categoryId()->value, array_map(static fn (CategoryId $id): string => $id->value, $categories), true)) {
                $this->categories->lockForWrite($discussion->categoryId());
                $categories[] = $discussion->categoryId();
            }

            $change($discussion);
            $this->discussions->save($discussion);

            $this->refresh($discussionId, $categories);

            return $discussion;
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

        $this->notices->forgetDiscussion($discussionId);
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
     * Hands a post that got its number to the notices; one awaiting approval waits for it.
     */
    private function announced(Post $post): Post
    {
        if ($post->isVisible()) {
            $this->notices->postPublished($post->id());
        }

        return $post;
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
