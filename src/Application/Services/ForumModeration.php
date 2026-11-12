<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\ModerationLogEntry;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Entities\SubjectDiscussion;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Events\ForumPostApproved;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\RedirectRepositoryInterface;
use Modules\Forum\Domain\Repositories\ReportRepositoryInterface;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * What moderators do in a discussion: pin, close, hide, rename, move and delete it; hide or show
 * a post (with a reason), edit someone else's, and approve or reject what awaits approval.
 *
 * Each action checks first that the person moderates the discussion (what they may not even read
 * is not found), runs under the writer's locks, and logs who did it in the same transaction.
 * What moderation does is marked as theirs (a close, a title), so the forum never undoes it.
 * Acting on something already in that state (closing a closed discussion, showing a shown
 * post) throws the domain's InvalidDiscussionException or InvalidPostException.
 */
final readonly class ForumModeration
{
    public function __construct(
        private DiscussionRepositoryInterface $discussions,
        private PostRepositoryInterface $posts,
        private CategoryRepositoryInterface $categories,
        private SubjectDiscussionRepositoryInterface $subjects,
        private ForumAccessInterface $access,
        private CategoryAccessPolicy $policy,
        private ForumWriter $writer,
        private ModerationLogger $logger,
        private DiscussionAnnouncer $announcer,
        private ReportRepositoryInterface $reports,
        private RedirectRepositoryInterface $redirects,
    ) {}

    /** At most this many posts are split off at once */
    public const int MAX_SPLIT = 500;

    /**
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException
     */
    public function pin(Authenticatable $moderator, DiscussionId $id): Discussion
    {
        return $this->change($moderator, $id, ModerationAction::DiscussionPinned, static fn (Discussion $discussion) => $discussion->pin());
    }

    /**
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException
     */
    public function unpin(Authenticatable $moderator, DiscussionId $id): Discussion
    {
        return $this->change($moderator, $id, ModerationAction::DiscussionUnpinned, static fn (Discussion $discussion) => $discussion->unpin());
    }

    /**
     * Closed by moderation: the forum never opens it again by itself.
     *
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException
     */
    public function close(Authenticatable $moderator, DiscussionId $id, ?string $reason = null): Discussion
    {
        return $this->change($moderator, $id, ModerationAction::DiscussionClosed, static fn (Discussion $discussion) => $discussion->close(), $reason);
    }

    /**
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException
     */
    public function reopen(Authenticatable $moderator, DiscussionId $id): Discussion
    {
        return $this->change($moderator, $id, ModerationAction::DiscussionReopened, static fn (Discussion $discussion) => $discussion->reopen());
    }

    /**
     * Hidden: only its moderators read it, and it leaves the lists and the counters.
     *
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException
     */
    public function hide(Authenticatable $moderator, DiscussionId $id, ?string $reason = null): Discussion
    {
        return $this->change($moderator, $id, ModerationAction::DiscussionHidden, static fn (Discussion $discussion) => $discussion->hide(), $reason);
    }

    /**
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException
     */
    public function show(Authenticatable $moderator, DiscussionId $id): Discussion
    {
        return $this->change($moderator, $id, ModerationAction::DiscussionShown, static fn (Discussion $discussion) => $discussion->unhide());
    }

    /**
     * Renamed by moderation: the forum stops following its publication's title.
     *
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException
     * @throws ModerationRefusedException for a title out of bounds
     */
    public function rename(Authenticatable $moderator, DiscussionId $id, string $title): Discussion
    {
        $title = $this->checkedTitle($title);

        $before = $this->moderated($moderator, $id)->title()->value;

        return $this->change(
            $moderator,
            $id,
            ModerationAction::DiscussionRenamed,
            static fn (Discussion $discussion) => $discussion->renameByModeration(new DiscussionTitle($title)),
            details: ['from' => $before, 'to' => $title],
        );
    }

    /**
     * Moves it into another category the moderator moderates. Never into the categories
     * publications fill, and never a publication's own discussion, which stays where the forum
     * keeps it.
     *
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException
     * @throws ModerationRefusedException
     */
    public function move(Authenticatable $moderator, DiscussionId $id, CategoryId $to, ?string $reason = null): Discussion
    {
        $discussion = $this->moderated($moderator, $id);

        if ($discussion->categoryId()->equals($to)) {
            throw ModerationRefusedException::sameCategory();
        }

        if ($this->subjects->findByDiscussion($id) instanceof SubjectDiscussion) {
            throw ModerationRefusedException::automaticDiscussion($id->value);
        }

        $target = $this->categories->find($to);
        if (! $target instanceof Category || ! $this->isMoveTarget($moderator, $target)) {
            throw ModerationRefusedException::moveTarget($to->value);
        }

        $from = $this->categories->find($discussion->categoryId());

        return $this->change(
            $moderator,
            $id,
            ModerationAction::DiscussionMoved,
            static fn (Discussion $locked) => $locked->moveTo($to),
            $reason,
            ['from' => $from?->name(), 'to' => $target->name()],
            moveTo: $to,
        );
    }

    /**
     * The categories a discussion may be moved into by this moderator.
     *
     * @return list<Category>
     */
    public function moveTargets(Authenticatable $moderator, Discussion $discussion): array
    {
        if ($this->subjects->findByDiscussion($discussion->id()) instanceof SubjectDiscussion) {
            return [];
        }

        return array_values(array_filter(
            $this->categories->all(),
            fn (Category $category): bool => ! $category->id()->equals($discussion->categoryId()) && $this->isMoveTarget($moderator, $category),
        ));
    }

    /**
     * Whether the person may split posts off the discussion or merge it into another: they
     * moderate it, it is not a publication's own discussion and it does not await approval.
     */
    public function canRestructure(?Authenticatable $user, Discussion $discussion): bool
    {
        return $user instanceof Authenticatable
            && ! $discussion->isAwaitingApproval()
            && $this->access->canModerate($user, $discussion)
            && ! $this->subjects->findByDiscussion($discussion->id()) instanceof SubjectDiscussion;
    }

    /**
     * Where posts split off the discussion may go: its own category first, then every other
     * the moderator may move discussions into.
     *
     * @return list<Category>
     */
    public function splitCategories(Authenticatable $moderator, Discussion $discussion): array
    {
        if (! $this->canRestructure($moderator, $discussion)) {
            return [];
        }

        $own = $this->categories->find($discussion->categoryId());

        return [
            ...($own instanceof Category ? [$own] : []),
            ...array_values(array_filter(
                $this->categories->all(),
                fn (Category $category): bool => ! $category->id()->equals($discussion->categoryId()) && $this->isMoveTarget($moderator, $category),
            )),
        ];
    }

    /**
     * Splits numbered posts off the discussion (never its first) into a new one with its own
     * title, in its category or another the moderator may move discussions into. They are
     * numbered from 1 there, in their order; the original keeps its gaps, and each old address
     * leads to the post's new place. Logged on the original.
     *
     * @param  list<int>  $numbers
     *
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException
     * @throws ModerationRefusedException
     */
    public function split(Authenticatable $moderator, DiscussionId $id, array $numbers, string $title, CategoryId $categoryId, ?string $reason = null): Discussion
    {
        $discussion = $this->moderated($moderator, $id);
        $this->assertRestructurable($discussion);

        $unique = array_values(array_unique($numbers));
        if ($numbers === [] || count($unique) !== count($numbers) || count($numbers) > self::MAX_SPLIT || min($numbers) < 2) {
            throw ModerationRefusedException::splitSelection();
        }
        sort($unique);
        if (count($this->posts->withNumbers($id, $unique)) !== count($unique)) {
            throw ModerationRefusedException::splitSelection();
        }

        $title = $this->checkedTitle($title);
        if (! $categoryId->equals($discussion->categoryId())) {
            $target = $this->categories->find($categoryId);
            if (! $target instanceof Category || ! $this->isMoveTarget($moderator, $target)) {
                throw ModerationRefusedException::moveTarget($categoryId->value);
            }
        }
        $this->checkedReason($reason);

        return DB::transaction(function () use ($moderator, $discussion, $unique, $title, $categoryId, $reason): Discussion {
            $moved = $this->writer->splitDiscussion($discussion->id(), $unique, $categoryId, new DiscussionTitle($title), CarbonImmutable::now());
            $this->logger->record(ModerationAction::DiscussionSplit, $moderator, $discussion, reason: $reason, details: [
                'to_number' => $moved->discussion->number(),
                'to_title' => $moved->discussion->title()->value,
                'posts' => $moved->count(),
                'numbers' => implode(', ', $unique),
            ]);

            return $moved->discussion;
        });
    }

    /**
     * The discussion the moderator may merge this one into, by its public number (or the
     * number a discussion had before it was merged into another).
     *
     * @throws DiscussionNotFoundException when there is none the moderator may read
     * @throws ModerationRefusedException
     */
    public function mergeTarget(Authenticatable $moderator, Discussion $absorbed, int $number): Discussion
    {
        $target = $this->discussions->findByNumber($number);
        if (! $target instanceof Discussion) {
            $into = $this->redirects->mergedDiscussion($number)?->into;
            $target = $into instanceof DiscussionId ? $this->discussions->find($into) : null;
        }

        if (! $target instanceof Discussion || ! $this->access->canRead($moderator, $target)) {
            throw DiscussionNotFoundException::withId((string) $number);
        }

        $this->assertMergeTarget($moderator, $absorbed, $target);

        return $target;
    }

    /**
     * Merges the discussion into another the moderator also moderates: its posts are appended
     * after that one's last, in the order they were published, and it goes away; its address
     * and its posts' lead to their new place. Logged on the merged discussion.
     *
     * @return Discussion the discussion it joined
     *
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException
     * @throws ModerationRefusedException
     */
    public function merge(Authenticatable $moderator, DiscussionId $id, DiscussionId $intoId, ?string $reason = null): Discussion
    {
        $absorbed = $this->moderated($moderator, $id);
        $this->assertRestructurable($absorbed);

        $into = $this->discussions->find($intoId);
        if (! $into instanceof Discussion || ! $this->access->canRead($moderator, $into)) {
            throw DiscussionNotFoundException::withId($intoId->value);
        }
        $this->assertMergeTarget($moderator, $absorbed, $into);
        $this->checkedReason($reason);

        return DB::transaction(function () use ($moderator, $absorbed, $into, $reason): Discussion {
            $moved = $this->writer->mergeDiscussion($absorbed->id(), $into->id(), CarbonImmutable::now());
            $this->logger->record(ModerationAction::DiscussionMerged, $moderator, $absorbed, reason: $reason, details: [
                'to_number' => $into->number(),
                'to_title' => $into->title()->value,
                'posts' => $moved->count(),
            ]);

            return $this->discussions->findOrFail($into->id());
        });
    }

    /**
     * Whether the person may delete the discussion for good: only whoever moderates the whole
     * forum, since nothing of it is kept but its line in the log.
     */
    public function canDelete(?Authenticatable $user, Discussion $discussion): bool
    {
        return $user instanceof Authenticatable && $this->policy->canModerateForum($user) && $this->access->canModerate($user, $discussion);
    }

    /**
     * Deletes the discussion and every post in it, for good. Its line in the moderation log
     * keeps its title and number.
     *
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException unless the person moderates the whole forum
     */
    public function delete(Authenticatable $moderator, DiscussionId $id, ?string $reason = null): void
    {
        $discussion = $this->moderated($moderator, $id);
        if (! $this->canDelete($moderator, $discussion)) {
            throw ForumActionDeniedException::because('delete a discussion');
        }

        $this->checkedReason($reason);
        $posts = count($this->posts->inDiscussion($discussion->id()));

        DB::transaction(function () use ($moderator, $discussion, $reason, $posts): void {
            $this->writer->deleteDiscussion($discussion->id());
            $this->logger->record(ModerationAction::DiscussionDeleted, $moderator, $discussion, reason: $reason, details: ['posts' => $posts]);
        });
    }

    /**
     * Hides a post with a reason its author and its moderators read.
     *
     * @throws PostNotFoundException
     * @throws ForumActionDeniedException
     * @throws InvalidPostException unless it is visible
     * @throws ModerationRefusedException for a reason too long
     */
    public function hidePost(Authenticatable $moderator, PostId $id, ?string $reason = null): Post
    {
        if ($reason !== null && mb_strlen(trim($reason)) > Post::MAX_HIDDEN_REASON_LENGTH) {
            throw ModerationRefusedException::reasonTooLong(Post::MAX_HIDDEN_REASON_LENGTH);
        }

        return DB::transaction(function () use ($moderator, $id, $reason): Post {
            $hidden = $this->changePost($moderator, $id, ModerationAction::PostHidden, static fn (Post $post) => $post->hide($reason), $reason);
            // Hiding a reported post settles its reports
            $this->settleReports($moderator, $id, ReportResolution::Hidden, $reason);

            return $hidden;
        });
    }

    /**
     * @throws PostNotFoundException
     * @throws ForumActionDeniedException
     */
    public function showPost(Authenticatable $moderator, PostId $id): Post
    {
        return $this->changePost($moderator, $id, ModerationAction::PostShown, static fn (Post $post) => $post->restore());
    }

    /**
     * Replaces what someone else's post says; the version it replaces stays in its history.
     *
     * @throws PostNotFoundException
     * @throws ForumActionDeniedException
     * @throws InvalidPostException once its author deleted it
     */
    public function editPost(Authenticatable $moderator, PostId $id, PostBody $body): Post
    {
        [$post, $discussion] = $this->moderatedPost($moderator, $id);

        return DB::transaction(function () use ($moderator, $id, $body, $post, $discussion): Post {
            $edited = $this->writer->editPost($id, $body, ForumBrowser::accountId($moderator), CarbonImmutable::now());

            // The same document again changes nothing, and logs nothing
            if (! $post->body()->sameDocumentAs($edited->body())) {
                $this->logger->record(ModerationAction::PostEdited, $moderator, $discussion, $edited);
                // Editing a reported post settles its reports
                $this->settleReports($moderator, $id, ReportResolution::Edited);
            }

            return $edited;
        });
    }

    /**
     * Publishes a post that awaited approval, with the next number. Approving the first post of
     * a discussion that waited for it opens the discussion to its readers, and announces it
     * (after the commit, once) when it would have been announced on starting.
     *
     * @throws PostNotFoundException
     * @throws ForumActionDeniedException
     * @throws InvalidPostException unless it awaits approval
     */
    public function approve(Authenticatable $moderator, PostId $id): Post
    {
        [$post, $discussion] = $this->moderatedPost($moderator, $id);
        if ($post->status() !== PostStatus::Pending) {
            throw InvalidPostException::notPending($id->value);
        }

        return DB::transaction(function () use ($moderator, $id, $discussion): Post {
            $approved = $this->writer->approvePost($id, CarbonImmutable::now());
            $this->logger->record(ModerationAction::PostApproved, $moderator, $discussion, $approved);

            $event = new ForumPostApproved($approved->id()->value, $discussion->id()->value, $approved->authorId(), $discussion->isAwaitingApproval());
            DB::afterCommit(static fn () => event($event));

            if ($discussion->isAwaitingApproval()) {
                $opened = $this->discussions->findOrFail($discussion->id());
                $category = $this->categories->find($opened->categoryId());
                if ($category instanceof Category) {
                    $this->announcer->announce($opened, $category, $approved->body(), $this->discussions->topicsOf($opened->id()));
                }
            }

            return $approved;
        });
    }

    /**
     * Rejects a post that awaited approval: it goes away, as it never had a place. Rejecting the
     * first post of a discussion that waited for it takes the discussion away with it.
     *
     * @throws PostNotFoundException
     * @throws ForumActionDeniedException
     * @throws InvalidPostException unless it awaits approval
     */
    public function reject(Authenticatable $moderator, PostId $id, ?string $reason = null): void
    {
        [$post, $discussion] = $this->moderatedPost($moderator, $id);
        if ($post->status() !== PostStatus::Pending) {
            throw InvalidPostException::notPending($id->value);
        }

        $this->checkedReason($reason);

        DB::transaction(function () use ($moderator, $post, $discussion, $reason): void {
            if ($discussion->isAwaitingApproval()) {
                $this->writer->deleteDiscussion($discussion->id());
            } else {
                $this->writer->discardPendingPost($post->id());
            }

            $this->logger->record(ModerationAction::PostRejected, $moderator, $discussion, $post, $reason, ['whole_discussion' => $discussion->isAwaitingApproval()]);
        });
    }

    /**
     * Settles every open report of the post the same way, and logs it once. Nothing to settle
     * logs nothing.
     *
     * @return int how many reports it settled
     *
     * @throws PostNotFoundException
     * @throws ForumActionDeniedException unless the person moderates the post
     */
    public function settleReports(Authenticatable $moderator, PostId $id, ReportResolution $resolution, ?string $reason = null): int
    {
        [$post, $discussion] = $this->moderatedPost($moderator, $id);
        $this->checkedReason($reason);

        return DB::transaction(function () use ($moderator, $post, $discussion, $resolution, $reason): int {
            $open = $this->reports->openOf($post->id());
            if ($open === []) {
                return 0;
            }

            $now = CarbonImmutable::now();
            foreach ($open as $report) {
                $report->resolve($resolution, ForumBrowser::accountId($moderator), $now);
                $this->reports->save($report);
            }

            $this->logger->record(ModerationAction::ReportResolved, $moderator, $discussion, $post, $reason, [
                'resolution' => $resolution->value,
                'reports' => count($open),
            ]);

            return count($open);
        });
    }

    /**
     * The discussion, when the person moderates it.
     *
     * @throws DiscussionNotFoundException when they may not even read it
     * @throws ForumActionDeniedException when they read it but do not moderate it
     */
    public function moderated(Authenticatable $moderator, DiscussionId $id): Discussion
    {
        $discussion = $this->discussions->find($id);
        if (! $discussion instanceof Discussion || ! $this->access->canRead($moderator, $discussion)) {
            throw DiscussionNotFoundException::withId($id->value);
        }

        if (! $this->access->canModerate($moderator, $discussion)) {
            throw ForumActionDeniedException::because('moderate this discussion');
        }

        return $discussion;
    }

    /**
     * @param  callable(Discussion): void  $apply
     * @param  array<string, string|int|bool|null>  $details
     *
     * @throws DiscussionNotFoundException
     * @throws ForumActionDeniedException
     */
    private function change(Authenticatable $moderator, DiscussionId $id, ModerationAction $action, callable $apply, ?string $reason = null, array $details = [], ?CategoryId $moveTo = null): Discussion
    {
        $discussion = $this->moderated($moderator, $id);

        // Awaiting approval it has no readers yet: the only thing to do is approve or reject it
        if ($discussion->isAwaitingApproval()) {
            throw ModerationRefusedException::awaitingApproval($id->value);
        }

        $this->checkedReason($reason);

        return DB::transaction(function () use ($moderator, $id, $action, $apply, $reason, $details, $moveTo): Discussion {
            $changed = $this->writer->changeDiscussion($id, $apply(...), $moveTo);
            $this->logger->record($action, $moderator, $changed, reason: $reason, details: $details);

            return $changed;
        });
    }

    /**
     * @param  callable(Post): void  $apply
     *
     * @throws PostNotFoundException
     * @throws ForumActionDeniedException
     */
    private function changePost(Authenticatable $moderator, PostId $id, ModerationAction $action, callable $apply, ?string $reason = null): Post
    {
        [, $discussion] = $this->moderatedPost($moderator, $id);

        return DB::transaction(function () use ($moderator, $id, $action, $apply, $reason, $discussion): Post {
            $changed = $this->writer->changePost($id, $apply(...));
            $this->logger->record($action, $moderator, $discussion, $changed, $reason);

            return $changed;
        });
    }

    /**
     * @return array{Post, Discussion}
     *
     * @throws PostNotFoundException when the person may not read its discussion
     * @throws ForumActionDeniedException when they read it but do not moderate it
     */
    private function moderatedPost(Authenticatable $moderator, PostId $id): array
    {
        $post = $this->posts->find($id);
        if (! $post instanceof Post) {
            throw PostNotFoundException::withId($id->value);
        }

        try {
            $discussion = $this->moderated($moderator, $post->discussionId());
        } catch (DiscussionNotFoundException) {
            throw PostNotFoundException::withId($id->value);
        }

        return [$post, $discussion];
    }

    /**
     * @throws ModerationRefusedException
     */
    private function assertRestructurable(Discussion $discussion): void
    {
        if ($this->subjects->findByDiscussion($discussion->id()) instanceof SubjectDiscussion) {
            throw ModerationRefusedException::automaticDiscussion($discussion->id()->value);
        }

        if ($discussion->isAwaitingApproval()) {
            throw ModerationRefusedException::awaitingApproval($discussion->id()->value);
        }
    }

    /**
     * @throws ModerationRefusedException
     */
    private function assertMergeTarget(Authenticatable $moderator, Discussion $absorbed, Discussion $target): void
    {
        if ($target->id()->equals($absorbed->id()) || ! $this->access->canModerate($moderator, $target)) {
            throw ModerationRefusedException::mergeTarget($target->id()->value);
        }

        $this->assertRestructurable($target);
    }

    /**
     * The title with its spaces tidied.
     *
     * @throws ModerationRefusedException for a title out of bounds
     */
    private function checkedTitle(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $length = mb_strlen($title);
        if ($length < DiscussionStarter::MIN_TITLE_LENGTH || $length > DiscussionStarter::MAX_TITLE_LENGTH) {
            throw ModerationRefusedException::title();
        }

        return $title;
    }

    private function isMoveTarget(Authenticatable $moderator, Category $category): bool
    {
        return ! $category->isSystem() && $this->policy->isAvailable($category) && $this->policy->canModerate($moderator, $category);
    }

    /**
     * @throws ModerationRefusedException
     */
    private function checkedReason(?string $reason): void
    {
        if ($reason !== null && mb_strlen(trim($reason)) > ModerationLogEntry::MAX_REASON_LENGTH) {
            throw ModerationRefusedException::reasonTooLong(ModerationLogEntry::MAX_REASON_LENGTH);
        }
    }
}
