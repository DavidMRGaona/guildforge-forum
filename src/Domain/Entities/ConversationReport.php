<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Domain\ValueObjects\ConversationReportId;

/**
 * Someone telling moderation about a message of a private conversation. Moderation never opens
 * the conversation: it reads the copy taken when the report was made, the message and the few
 * around it, as plain text with the id of each author (names are read when it is shown, so an
 * anonymized account shows its anonymous name). The copy does not change when the message is
 * deleted or the conversation goes.
 *
 * Settled as hidden (the message is hidden too) or with no action.
 */
final class ConversationReport
{
    public const int MAX_NOTE_LENGTH = 500;

    private readonly ?string $reporterId;

    private readonly ?string $messageAuthorId;

    private readonly ?string $note;

    private ?string $resolvedBy;

    /**
     * @param  list<array{number: int, kind: string, authorId: string|null, subjectUserId: string|null, text: string, reported: bool, at: string}>  $context
     *
     * @throws InvalidModerationRecordException
     */
    public function __construct(
        private readonly ConversationReportId $id,
        private readonly ?ConversationMessageId $messageId,
        ?string $messageAuthorId,
        ?string $reporterId,
        private readonly ReportReason $reason,
        ?string $note,
        private readonly array $context,
        private readonly DateTimeImmutable $createdAt,
        private ?ReportResolution $resolution = null,
        ?string $resolvedBy = null,
        private ?DateTimeImmutable $resolvedAt = null,
    ) {
        $this->reporterId = $this->account($reporterId);
        $this->messageAuthorId = $this->account($messageAuthorId);
        $this->resolvedBy = $this->account($resolvedBy);

        $note = $note === null ? '' : trim($note);
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw InvalidModerationRecordException::noteTooLong(self::MAX_NOTE_LENGTH);
        }
        $this->note = $note === '' ? null : $note;
    }

    /**
     * @param  list<array{number: int, kind: string, authorId: string|null, subjectUserId: string|null, text: string, reported: bool, at: string}>  $context
     *
     * @throws InvalidModerationRecordException
     */
    public static function file(ConversationReportId $id, ConversationMessage $message, string $reporterId, ReportReason $reason, ?string $note, array $context, DateTimeImmutable $at): self
    {
        return new self($id, $message->id(), $message->authorId(), $reporterId, $reason, $note, $context, $at);
    }

    /**
     * Hidden or no action; editing is not something moderation does to a private message.
     *
     * @throws InvalidModerationRecordException when it was resolved already
     */
    public function resolve(ReportResolution $resolution, ?string $moderatorId, DateTimeImmutable $at): void
    {
        if (! $this->isOpen()) {
            throw InvalidModerationRecordException::alreadyResolved($this->id->value);
        }

        $this->resolution = $resolution === ReportResolution::Hidden ? ReportResolution::Hidden : ReportResolution::NoAction;
        $this->resolvedBy = $this->account($moderatorId);
        $this->resolvedAt = $at;
    }

    public function isOpen(): bool
    {
        return ! $this->resolution instanceof ReportResolution;
    }

    public function id(): ConversationReportId
    {
        return $this->id;
    }

    /**
     * The message reported, while it still exists.
     */
    public function messageId(): ?ConversationMessageId
    {
        return $this->messageId;
    }

    public function messageAuthorId(): ?string
    {
        return $this->messageAuthorId;
    }

    public function reporterId(): ?string
    {
        return $this->reporterId;
    }

    public function reason(): ReportReason
    {
        return $this->reason;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    /**
     * @return list<array{number: int, kind: string, authorId: string|null, subjectUserId: string|null, text: string, reported: bool, at: string}>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function resolution(): ?ReportResolution
    {
        return $this->resolution;
    }

    public function resolvedBy(): ?string
    {
        return $this->resolvedBy;
    }

    public function resolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    private function account(?string $id): ?string
    {
        return $id === null ? null : AccountId::normalize($id);
    }
}
