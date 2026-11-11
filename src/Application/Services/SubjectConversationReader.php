<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Publications\Enums\PublicationState;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\DTOs\SubjectClosedReason;
use Modules\Forum\Application\DTOs\SubjectConversationDTO;
use Modules\Forum\Application\Publications\PublicationFacts;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\SubjectDiscussion;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostNumber;
use Modules\Forum\Domain\ValueObjects\PublicationKey;

/**
 * The conversation a record's page shows (a game table, a campaign, an event): the last posts
 * of its publication's discussion, read with the forum's own rules. A record without a
 * discussion, or one the reader may not read, has none (null), whatever the reason, so the
 * page cannot tell a private discussion from a missing one.
 */
final readonly class SubjectConversationReader
{
    /** Posts the page shows first */
    public const int LATEST = 5;

    /** Posts each "earlier ones" adds */
    public const int EARLIER = 10;

    /** The forum's opening post (number 1) repeats the page it sits in, so the page never shows it */
    private const int FIRST_SHOWN = 2;

    public function __construct(
        private SubjectDiscussionRepositoryInterface $subjects,
        private DiscussionRepositoryInterface $discussions,
        private PostRepositoryInterface $posts,
        private ForumAccessInterface $access,
        private PostingService $posting,
        private PostContentPresenter $presenter,
        private DiscussionReadsInterface $reads,
    ) {}

    /**
     * @param  int|null  $before  the earlier posts below this number; null for the latest ones
     */
    public function read(?Authenticatable $reader, string $type, string $id, ?int $before = null): ?SubjectConversationDTO
    {
        try {
            $key = new PublicationKey($type, $id);
        } catch (InvalidArgumentException) {
            return null;
        }

        $record = $this->subjects->find($key);
        $discussionId = $record?->discussionId();
        if (! $record instanceof SubjectDiscussion || ! $discussionId instanceof DiscussionId) {
            return null;
        }

        $discussion = $this->discussions->find($discussionId);
        if (! $discussion instanceof Discussion || ! $this->access->canRead($reader, $discussion)) {
            return null;
        }

        $latest = $before === null || $before < 1;
        $posts = $this->posts->lastNumbered(
            $discussionId,
            new PostNumber(self::FIRST_SHOWN),
            $latest ? null : new PostNumber($before),
            $latest ? self::LATEST : self::EARLIER,
        );

        $lowest = $posts === [] ? null : $posts[0]->number()?->value;
        $olderCount = $lowest === null ? 0 : max(0, $lowest - self::FIRST_SHOWN);

        // Posts awaiting approval have no place yet: their author and moderators see them last
        $readerId = ForumBrowser::accountId($reader);
        if ($latest && $readerId !== null) {
            $moderates = $this->access->canModerate($reader, $discussion);
            foreach ($this->posts->pendingIn($discussionId) as $pending) {
                if ($moderates || $pending->authorId() === $readerId) {
                    $posts[] = $pending;
                }
            }
        }

        return new SubjectConversationDTO(
            key: $key,
            discussion: $discussion,
            posts: $posts,
            contents: $this->presenter->present($posts, $discussion, $reader),
            olderCount: $olderCount,
            highestNumber: $this->posts->highestNumber($discussionId)->value ?? 0,
            lastReadNumber: $readerId === null ? null : $this->reads->readThrough($readerId, $discussionId, $discussion->categoryId()),
            canPost: $this->posting->canPost($reader, $discussion),
            closedReason: $this->closedReason($discussion, $record),
        );
    }

    private function closedReason(Discussion $discussion, SubjectDiscussion $record): ?SubjectClosedReason
    {
        if ($discussion->status() !== DiscussionStatus::Closed) {
            return null;
        }

        if (! $discussion->isClosedBySystem()) {
            return SubjectClosedReason::Moderation;
        }

        $facts = PublicationFacts::fromArray($record->facts());

        return match (true) {
            ! $facts instanceof PublicationFacts => SubjectClosedReason::Closed,
            ! $facts->isPublished() => SubjectClosedReason::Withdrawn,
            $facts->state === PublicationState::Cancelled => SubjectClosedReason::Cancelled,
            default => SubjectClosedReason::Closed,
        };
    }
}
