<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Conversations;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ConversationNoticesInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Application\Services\ForumSuspensions;
use Modules\Forum\Application\Services\ModerationLogger;
use Modules\Forum\Domain\ConversationRules;
use Modules\Forum\Domain\Entities\ConversationMessage;
use Modules\Forum\Domain\Entities\ConversationReport;
use Modules\Forum\Domain\Enums\ConversationEntryKind;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Exceptions\ConversationNotFoundException;
use Modules\Forum\Domain\Exceptions\ConversationRefusedException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidConversationException;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\Repositories\ConversationReportRepositoryInterface;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Domain\ValueObjects\ConversationReportId;

/**
 * Reporting a private message, and settling the reports. This is the only way moderation reads
 * anything of a private conversation: the report carries a copy of the message and of the
 * three messages before and after it, taken when it was made. Whoever moderates the whole forum
 * (and is not suspended) settles them; moderators of a category do not, since a conversation
 * belongs to none.
 *
 * Anyone in the conversation reports a message of someone else that is shown, once while their
 * report is open, unless they are barred. Hiding it hides the message for everyone in the
 * conversation and settles every open report of it; no action only settles them.
 */
final readonly class ConversationReportService
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private ConversationReportRepositoryInterface $reports,
        private ConversationAccess $access,
        private ForumSuspensions $suspensions,
        private CategoryAccessPolicy $policy,
        private ModerationLogger $logger,
        private ConversationNoticesInterface $notices,
    ) {}

    public function canReport(?Authenticatable $user, ConversationMessage $message): bool
    {
        $userId = ForumBrowser::accountId($user);

        return $userId !== null
            && $message->isShown()
            && $message->authorId() !== $userId
            && $this->mayReportIn($user, $message->conversationId());
    }

    /**
     * Whether the person reports messages of others in the conversation: in it, and not barred.
     */
    public function mayReportIn(?Authenticatable $user, ConversationId $conversationId): bool
    {
        return $this->access->canRead($user, $conversationId) && ! $this->suspensions->isBarred($user);
    }

    /**
     * Whether the person settles reports of private messages.
     */
    public function canSettle(?Authenticatable $user): bool
    {
        return $this->policy->canModerateForum($user);
    }

    /**
     * @throws ConversationNotFoundException when the person is not in the conversation
     * @throws ConversationRefusedException when they may not report it
     * @throws InvalidModerationRecordException for a note too long
     */
    public function report(Authenticatable $reporter, ConversationMessageId $messageId, ReportReason $reason, ?string $note): ConversationReport
    {
        $message = $this->conversations->findMessage($messageId);
        if (! $message instanceof ConversationMessage || ! $this->access->canRead($reporter, $message->conversationId())) {
            throw ConversationNotFoundException::withId($messageId->value);
        }
        if (! $this->canReport($reporter, $message)) {
            throw ConversationRefusedException::because(ConversationRefusedException::NOT_ALLOWED);
        }

        $reporterId = (string) ForumBrowser::accountId($reporter);
        $open = $this->reports->openBy($messageId, $reporterId);
        if ($open instanceof ConversationReport) {
            return $open;
        }

        $context = [];
        foreach ($this->conversations->around($message->conversationId(), $message->number(), ConversationRules::REPORT_CONTEXT, ConversationRules::REPORT_CONTEXT) as $entry) {
            $context[] = [
                'number' => $entry->number(),
                'kind' => $entry->isMessage() && ! $entry->isShown() ? 'deleted' : $entry->kind()->value,
                'authorId' => $entry->authorId(),
                'subjectUserId' => $entry->subjectUserId(),
                'text' => $entry->kind() === ConversationEntryKind::Message && $entry->isShown() ? $entry->body()->plainText : '',
                'reported' => $entry->id()->equals($message->id()),
                'at' => $entry->createdAt()->format(DATE_ATOM),
            ];
        }

        $report = ConversationReport::file(ConversationReportId::generate(), $message, $reporterId, $reason, $note, $context, CarbonImmutable::now()->toDateTimeImmutable());
        $this->reports->save($report);
        $this->notices->reported($report->id());

        return $report;
    }

    /**
     * Settles every open report of the reported message.
     *
     * @return int how many reports it settled
     *
     * @throws ConversationNotFoundException for a report that does not exist
     * @throws ForumActionDeniedException unless the person moderates the whole forum
     * @throws InvalidConversationException for a reason too long
     */
    public function resolve(Authenticatable $moderator, ConversationReportId $reportId, ReportResolution $resolution, ?string $reason = null): int
    {
        if (! $this->canSettle($moderator)) {
            throw ForumActionDeniedException::because('settle reports of private messages');
        }

        return DB::transaction(function () use ($moderator, $reportId, $resolution, $reason): int {
            $report = $this->reports->find($reportId);
            if (! $report instanceof ConversationReport) {
                throw ConversationNotFoundException::withId($reportId->value);
            }

            $messageId = $report->messageId();
            $open = $messageId instanceof ConversationMessageId ? $this->reports->openOf($messageId) : [];
            if ($report->isOpen() && $open === []) {
                $open = [$report];
            }

            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $hidden = false;
            if ($resolution === ReportResolution::Hidden && $messageId instanceof ConversationMessageId) {
                $message = $this->conversations->findMessage($messageId);
                if ($message instanceof ConversationMessage && $message->isShown()) {
                    $message->hide($reason, $now);
                    $this->conversations->saveMessage($message);
                    $hidden = true;
                }
            }

            $settled = 0;
            foreach ($open as $each) {
                $each->resolve($hidden ? ReportResolution::Hidden : ReportResolution::NoAction, ForumBrowser::accountId($moderator), $now);
                $this->reports->save($each);
                $settled++;
            }

            if ($settled > 0 || $hidden) {
                $this->logger->recordForAccount(
                    $hidden ? ModerationAction::PrivateMessageHidden : ModerationAction::PrivateReportsResolved,
                    $moderator,
                    (string) $report->messageAuthorId(),
                    $reason,
                    ['reports' => $settled],
                );
            }

            return $settled;
        });
    }

    /**
     * The open reports, oldest first, for the moderation queue.
     *
     * @return list<ConversationReport>
     */
    public function open(Authenticatable $moderator, int $limit): array
    {
        return $this->canSettle($moderator) ? $this->reports->open($limit) : [];
    }
}
