<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\DTOs\ConversationPageDTO;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostNumber;

/**
 * Reading a discussion: one page of posts at a time, and the reader's mark of how far they
 * read. The mark only moves when the page reports the posts it showed, and never past the last
 * numbered post, so opening a page alone marks nothing as read.
 */
final readonly class ConversationReader
{
    public function __construct(
        private DiscussionRepositoryInterface $discussions,
        private CategoryRepositoryInterface $categories,
        private PostRepositoryInterface $posts,
        private ForumAccessInterface $access,
        private PostingService $posting,
        private PostContentPresenter $presenter,
        private DiscussionReadsInterface $reads,
        private ForumSettingsInterface $settings,
    ) {}

    /**
     * The page holding the post asked for; without one, the page with the reader's first unread
     * post (the last page once they read everything), or the first page for guests and for
     * discussions they never opened.
     *
     * @throws DiscussionNotFoundException when the reader may not read it
     */
    public function open(?Authenticatable $reader, DiscussionId $id, ?int $number): ConversationPageDTO
    {
        $discussion = $this->readable($reader, $id);
        $category = $this->categories->find($discussion->categoryId());
        if (! $category instanceof Category) {
            throw DiscussionNotFoundException::withId($id->value);
        }

        $perPage = $this->settings->postsPerPage();
        $highest = $this->posts->highestNumber($id)->value ?? 0;
        $readerId = ForumBrowser::accountId($reader);
        $lastRead = $readerId === null ? null : $this->reads->readThrough($readerId, $id, $discussion->categoryId());

        $focus = $this->focus($number, $highest, $lastRead);
        $pageStart = $focus === null ? 1 : intdiv($focus - 1, $perPage) * $perPage + 1;

        $posts = $highest === 0 ? [] : $this->posts->numberedFrom($id, new PostNumber($pageStart), $perPage);
        $moderates = $this->access->canModerate($reader, $discussion);

        // Posts awaiting approval have no place yet: their author and moderators see them last
        if ($pageStart + $perPage > $highest && $readerId !== null) {
            foreach ($this->posts->pendingIn($id) as $pending) {
                if ($moderates || $pending->authorId() === $readerId) {
                    $posts[] = $pending;
                }
            }
        }

        return new ConversationPageDTO(
            discussion: $discussion,
            category: $category,
            topics: $this->discussions->topicsOf($id),
            posts: $posts,
            contents: $this->presenter->present($posts, $discussion, $reader),
            pageStart: $pageStart,
            perPage: $perPage,
            highestNumber: $highest,
            focusNumber: $focus,
            lastReadNumber: $lastRead,
            canPost: $this->posting->canPost($reader, $discussion),
            canModerate: $moderates,
            guestsRead: $this->access->canRead(null, $discussion),
        );
    }

    /**
     * Moves the reader's mark up to the number the page showed, never past the last numbered
     * post.
     *
     * @throws DiscussionNotFoundException when the reader may not read it
     */
    public function markRead(Authenticatable $reader, DiscussionId $id, int $number, DateTimeImmutable $at): void
    {
        $this->readable($reader, $id);

        $readerId = ForumBrowser::accountId($reader);
        $highest = $this->posts->highestNumber($id)->value ?? 0;
        if ($readerId === null || $highest === 0) {
            return;
        }

        $this->reads->advance($readerId, $id, min($number, $highest), $at);
    }

    /**
     * @throws DiscussionNotFoundException
     */
    private function readable(?Authenticatable $reader, DiscussionId $id): Discussion
    {
        $discussion = $this->discussions->find($id);
        if (! $discussion instanceof Discussion || ! $this->access->canRead($reader, $discussion)) {
            throw DiscussionNotFoundException::withId($id->value);
        }

        return $discussion;
    }

    private function focus(?int $number, int $highest, ?int $lastRead): ?int
    {
        if ($highest === 0) {
            return null;
        }

        if ($number !== null) {
            return max(1, min($number, $highest));
        }

        if ($lastRead === null || $lastRead === 0) {
            return null;
        }

        return min($lastRead + 1, $highest);
    }
}
