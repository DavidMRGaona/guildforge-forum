<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Publications;

use App\Application\Publications\Contracts\PublicationSourceInterface;
use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\Enums\PublicationState;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Forum\Application\Contracts\PublicationSyncQueueInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\SubjectDiscussion;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PublicationKey;

/**
 * Keeps the automatic discussion of a publication in step with the publication, as its source
 * gives it now:
 *
 * - A publication that is published and active gets one discussion, in the system category of its
 *   type, started by the system account. One that was cancelled or is over before it got one
 *   never gets one, and one whose discussion a moderator deleted is not given another.
 * - Each change worth telling is a reply of the system account: a new date, a place freed in a
 *   full table with open registrations, registrations closing, cancelling (which closes the
 *   discussion) and reactivating (which opens it again), taking down or deleting (which close it;
 *   nothing is ever deleted) and publishing again (which opens it). The first read only takes note.
 * - The forum only opens what it closed itself: a discussion a moderator closed stays closed.
 * - When the source cannot be read the check fails (and is retried): an error is never taken for
 *   a publication taken down. A type without a source (its module disabled) leaves things as
 *   they are.
 *
 * Checks of one publication run one after the other (its record is locked).
 */
final readonly class PublicationDiscussionSync
{
    /**
     * Types of the core's publication sources the forum announces, and their category.
     */
    public const array CATEGORIES = [
        'game-table' => SystemCategory::GameTables,
        'campaign' => SystemCategory::Campaigns,
        'event' => SystemCategory::Events,
    ];

    /** A check queued for registrations closing runs this long after the time */
    private const int CHECK_DELAY_SECONDS = 30;

    public function __construct(
        private PublicationSourceRegistryInterface $publications,
        private SubjectDiscussionRepositoryInterface $records,
        private CategoryRepositoryInterface $categories,
        private DiscussionRepositoryInterface $discussions,
        private ForumWriter $writer,
        private SystemAccountInterface $systemAccount,
        private PublicationPosts $posts,
        private PublicationSyncQueueInterface $queue,
    ) {}

    public static function announces(string $type): bool
    {
        return isset(self::CATEGORIES[$type]);
    }

    /**
     * @param  bool  $removed  its owner said it was deleted (only changes how its absence is told)
     */
    public function sync(PublicationKey $key, bool $removed = false): void
    {
        $category = self::CATEGORIES[$key->type] ?? null;
        $source = $this->publications->source($key->type);
        if (! $category instanceof SystemCategory || ! $source instanceof PublicationSourceInterface) {
            return;
        }

        // Read before locking anything; a failing source throws and the check is retried
        $publication = $source->find($key->id);

        $checkAt = DB::transaction(function () use ($key, $category, $publication, $removed): ?DateTimeImmutable {
            $record = $this->records->lockOrCreate($key);

            if (! $record->wasAnnounced()) {
                return $this->open($record, $category, $publication);
            }

            $discussionId = $record->discussionId();
            $discussion = $discussionId instanceof DiscussionId ? $this->discussions->find($discussionId) : null;

            $before = PublicationFacts::fromArray($record->facts());
            $after = $publication instanceof PublicationDTO
                ? PublicationFacts::of($publication, PublicationPosts::fingerprint($this->posts->opening($publication)))
                : ($before?->gone($removed) ?? null);

            if (! $after instanceof PublicationFacts) {
                return null;
            }

            if ($discussion instanceof Discussion && $before instanceof PublicationFacts) {
                $this->tell($record, $discussion, $before, $after, $publication);
            }

            $record->record($after->toArray(), $after->isLive());
            $this->records->save($record);

            return $this->checkTime($record, $publication);
        });

        if ($checkAt instanceof DateTimeImmutable) {
            $this->queue->queueAt($key, $checkAt);
        }
    }

    /**
     * Starts the discussion of a publication that never had one, if it is published and active.
     */
    private function open(SubjectDiscussion $record, SystemCategory $categoryKey, ?PublicationDTO $publication): ?DateTimeImmutable
    {
        if (! $publication instanceof PublicationDTO || $publication->state !== PublicationState::Active) {
            return null;
        }

        $category = $this->categories->findBySystemKey($categoryKey);
        if (! $category instanceof Category) {
            Log::warning('[Forum] System category missing: publication discussion not started', ['category' => $categoryKey->value, 'publication' => $record->key()->value()]);

            return null;
        }

        $now = now()->toImmutable();
        $discussion = Discussion::start(DiscussionId::generate(), $category->id(), $this->systemAccount->id(), $this->posts->title($publication), $now);
        $opening = $this->posts->opening($publication);
        $firstPost = $this->writer->startDiscussion($discussion, PostId::generate(), $opening, needsApproval: false);
        $this->discussions->replaceTopics($discussion->id(), $this->posts->topics($publication));

        $facts = PublicationFacts::of($publication, PublicationPosts::fingerprint($opening));
        $record->announce($discussion->id(), $firstPost->id(), $facts->toArray(), $facts->isLive());
        $this->records->save($record);

        return $this->checkTime($record, $publication);
    }

    /**
     * Tells what changed since the facts were stored, keeps the title, first post and tags in
     * step, and closes or opens the discussion.
     */
    private function tell(SubjectDiscussion $record, Discussion $discussion, PublicationFacts $before, PublicationFacts $after, ?PublicationDTO $publication): void
    {
        $now = now()->toImmutable();
        $author = $this->systemAccount->id();

        foreach ($this->notices($before, $after) as $notice) {
            $this->writer->reply($discussion->id(), PostId::generate(), $author, $this->posts->notice($notice, $publication), $now, needsApproval: false);
        }

        if ($publication instanceof PublicationDTO) {
            $this->refreshOpening($record, $before, $after, $publication, $author, $now);
            $this->discussions->replaceTopics($discussion->id(), $this->posts->topics($publication));
        }

        // Read again: replies refreshed it, and a moderator may have changed it meanwhile
        $discussion = $this->discussions->findOrFail($discussion->id());
        $changed = false;

        if ($publication instanceof PublicationDTO && $before->title !== $after->title) {
            $discussion->rename($this->posts->title($publication));
            $changed = true;
        }

        if ($after->wantsClosed()) {
            if ($discussion->status() === DiscussionStatus::Open) {
                $discussion->closeBySystem();
                $changed = true;
            }
        } elseif ($discussion->isClosedBySystem()) {
            // Only what the forum closed: a moderator closing or opening it since clears the mark
            $discussion->reopen();
            $changed = true;
        }

        if ($changed) {
            $this->writer->saveDiscussion($discussion);
        }
    }

    /**
     * @return list<PublicationNotice>
     */
    private function notices(PublicationFacts $before, PublicationFacts $after): array
    {
        if (! $after->isPublished()) {
            return $before->isPublished() ? [$after->presence === PublicationFacts::DELETED ? PublicationNotice::Deleted : PublicationNotice::Withdrawn] : [];
        }

        $notices = [];
        if (! $before->isPublished()) {
            $notices[] = PublicationNotice::Republished;
        }

        if ($after->state === PublicationState::Cancelled && $before->state !== PublicationState::Cancelled) {
            $notices[] = PublicationNotice::Cancelled;
        }

        if ($after->state === PublicationState::Active && $before->state === PublicationState::Cancelled) {
            $notices[] = PublicationNotice::Reactivated;
        }

        if ($after->state !== PublicationState::Active) {
            return $notices;
        }

        if ($after->startsAt !== null && $after->startsAt !== $before->startsAt) {
            $notices[] = PublicationNotice::Rescheduled;
        }

        if ($before->isFull && ! $after->isFull && $after->registrationOpen) {
            $notices[] = PublicationNotice::PlaceFreed;
        }

        if ($before->registrationOpen && ! $after->registrationOpen) {
            $notices[] = PublicationNotice::RegistrationClosed;
        }

        return $notices;
    }

    /**
     * Rewrites the first post when what it says changed (keeping what it said as a revision).
     * A first post that is gone, or that cannot be edited, is left alone.
     */
    private function refreshOpening(SubjectDiscussion $record, PublicationFacts $before, PublicationFacts $after, PublicationDTO $publication, string $author, DateTimeImmutable $now): void
    {
        $firstPostId = $record->firstPostId();
        if ($before->opening === $after->opening || ! $firstPostId instanceof PostId) {
            return;
        }

        try {
            $this->writer->editPost($firstPostId, $this->posts->opening($publication), $author, $now);
        } catch (PostNotFoundException|InvalidPostException) {
            // Removed or emptied meanwhile: nothing left to rewrite
        }
    }

    /**
     * When to check again by itself: once registrations close at a set time. Queued only when
     * that time changes, so saving twice queues it once.
     */
    private function checkTime(SubjectDiscussion $record, ?PublicationDTO $publication): ?DateTimeImmutable
    {
        $closesAt = $publication?->registrationClosesAt;
        if (! $publication instanceof PublicationDTO || ! $publication->registrationOpen || ! $closesAt instanceof DateTimeImmutable || $closesAt <= now()) {
            return null;
        }

        $at = $closesAt->modify('+'.self::CHECK_DELAY_SECONDS.' seconds');
        if ($record->nextCheckAt()?->getTimestamp() === $at->getTimestamp()) {
            return null;
        }

        $record->checkAt($at);
        $this->records->save($record);

        return $at;
    }
}
