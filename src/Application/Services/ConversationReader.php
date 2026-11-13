<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\ForumLiveSignalsInterface;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\DTOs\ConversationPageDTO;
use Modules\Forum\Application\DTOs\MovedPostsDTO;
use Modules\Forum\Application\Queries\MovedPostRowDTO;
use Modules\Forum\Application\Queries\MovedPostsQueryInterface;
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
        private MovedPostsQueryInterface $moved,
        private ForumLiveSignalsInterface $live,
    ) {}

    /**
     * The page holding the post asked for; without one, the page with the reader's first unread
     * post (the last page once they read everything), or the first page for guests and for
     * discussions they never opened.
     *
     * A page may be asked for by its place instead (`$page`, from 1): one whose numbers all
     * moved away has no post to ask for.
     *
     * @throws DiscussionNotFoundException when the reader may not read it
     */
    public function open(?Authenticatable $reader, DiscussionId $id, ?int $number, ?int $page = null): ConversationPageDTO
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

        if ($number === null && $page !== null && $highest > 0) {
            $focus = null;
            $pageStart = (min(max(1, $page), intdiv($highest - 1, $perPage) + 1) - 1) * $perPage + 1;
        } else {
            $focus = $this->focus($number, $highest, $lastRead);
            // The first unread number may have moved away: the first post after it is the one
            if ($number === null && $focus !== null) {
                $focus = $this->posts->firstNumberBetween($id, new PostNumber($focus), new PostNumber($highest))->value ?? $focus;
            }
            $pageStart = $focus === null ? 1 : intdiv($focus - 1, $perPage) * $perPage + 1;
        }

        // A page is a fixed run of numbers: where posts moved away it holds fewer, and says where they went
        $pageEnd = $pageStart + $perPage - 1;
        $posts = $highest === 0 ? [] : $this->posts->numberedBetween($id, new PostNumber($pageStart), new PostNumber($pageEnd));
        $isLastPage = $pageStart + $perPage > $highest;
        $moderates = $this->access->canModerate($reader, $discussion);

        // Posts awaiting approval have no place yet: their author and moderators see them last
        if ($isLastPage && $readerId !== null) {
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
            moved: $this->movedRuns($reader, $this->moved->inRange($id, $pageStart, $isLastPage ? null : $pageEnd)),
            pageAnchors: $highest === 0 ? [] : $this->pageAnchors($id, $pageStart, $perPage, $highest),
        );
    }

    /**
     * The number each page the page links to is reached by (its own, the previous, the next and
     * the last): its first number, or its first post's when that number moved away (its
     * address leads where the post went); null when all its numbers moved away.
     *
     * @return array<int, int|null> by the page's first number
     */
    private function pageAnchors(DiscussionId $id, int $pageStart, int $perPage, int $highest): array
    {
        $lastStart = intdiv($highest - 1, $perPage) * $perPage + 1;
        $starts = array_unique(array_filter(
            [$pageStart, $pageStart - $perPage, $pageStart + $perPage, $lastStart],
            static fn (int $start): bool => $start >= 1 && $start <= $lastStart,
        ));

        $anchors = [];
        foreach ($starts as $start) {
            $anchors[$start] = $this->posts->firstNumberBetween($id, new PostNumber($start), new PostNumber($start + $perPage - 1))?->value;
        }

        return $anchors;
    }

    /**
     * Consecutive numbers whose posts went to the same discussion, as one run each.
     *
     * @param  list<MovedPostRowDTO>  $rows  in order
     * @return list<MovedPostsDTO>
     */
    private function movedRuns(?Authenticatable $reader, array $rows): array
    {
        /** @var list<array{from: int, through: int, count: int, discussion: ?string, number: ?int}> $runs */
        $runs = [];
        foreach ($rows as $row) {
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last]['through'] === $row->fromNumber - 1 && $runs[$last]['discussion'] === $row->discussionId) {
                $runs[$last]['through'] = $row->fromNumber;
                $runs[$last]['count']++;

                continue;
            }
            $runs[] = ['from' => $row->fromNumber, 'through' => $row->fromNumber, 'count' => 1, 'discussion' => $row->discussionId, 'number' => $row->number];
        }

        $readable = [];
        $moved = [];
        foreach ($runs as $run) {
            $target = null;
            if ($run['discussion'] !== null) {
                if (! array_key_exists($run['discussion'], $readable)) {
                    $found = $this->discussions->find(DiscussionId::fromString($run['discussion']));
                    $readable[$run['discussion']] = $found instanceof Discussion && $this->access->canRead($reader, $found) ? $found : null;
                }
                $target = $readable[$run['discussion']];
            }

            $moved[] = new MovedPostsDTO($run['from'], $run['through'], $run['count'], $target, $target instanceof Discussion ? $run['number'] : null);
        }

        return $moved;
    }

    /**
     * Moves the reader's mark up to the number the page showed, never past the last numbered
     * post.
     *
     * @throws DiscussionNotFoundException when the reader may not read it
     */
    public function markRead(Authenticatable $reader, DiscussionId $id, int $number, DateTimeImmutable $at): void
    {
        $discussion = $this->readable($reader, $id);

        $readerId = ForumBrowser::accountId($reader);
        $highest = $this->posts->highestNumber($id)->value ?? 0;
        if ($readerId === null || $highest === 0) {
            return;
        }

        // The menu counts discussions with something unread: only reaching the end changes that
        $finishes = $number >= $highest && $this->reads->readThrough($readerId, $id, $discussion->categoryId()) < $highest;
        $this->reads->advance($readerId, $id, min($number, $highest), $at);
        if ($finishes) {
            $this->live->pulseChanged($readerId);
        }
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
