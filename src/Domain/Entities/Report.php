<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\ReportId;

/**
 * Someone telling moderation about a post: why (from a closed list, with an optional note),
 * and, once settled, how and by whom. Open until a moderator resolves it.
 */
final class Report
{
    public const int MAX_NOTE_LENGTH = 500;

    private readonly ?string $reporterId;

    private readonly ?string $note;

    private ?string $resolvedBy;

    /**
     * @throws InvalidModerationRecordException
     */
    public function __construct(
        private readonly ReportId $id,
        private readonly PostId $postId,
        ?string $reporterId,
        private readonly ReportReason $reason,
        ?string $note,
        private readonly DateTimeImmutable $createdAt,
        private ?ReportResolution $resolution = null,
        ?string $resolvedBy = null,
        private ?DateTimeImmutable $resolvedAt = null,
    ) {
        $this->reporterId = $this->account($reporterId);
        $this->resolvedBy = $this->account($resolvedBy);

        $note = $note === null ? '' : trim($note);
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw InvalidModerationRecordException::noteTooLong(self::MAX_NOTE_LENGTH);
        }
        $this->note = $note === '' ? null : $note;
    }

    /**
     * @throws InvalidModerationRecordException
     */
    public static function file(ReportId $id, PostId $postId, string $reporterId, ReportReason $reason, ?string $note, DateTimeImmutable $at): self
    {
        return new self($id, $postId, $reporterId, $reason, $note, $at);
    }

    /**
     * @throws InvalidModerationRecordException when it was resolved already
     */
    public function resolve(ReportResolution $resolution, ?string $moderatorId, DateTimeImmutable $at): void
    {
        if (! $this->isOpen()) {
            throw InvalidModerationRecordException::alreadyResolved($this->id->value);
        }

        $this->resolution = $resolution;
        $this->resolvedBy = $this->account($moderatorId);
        $this->resolvedAt = $at;
    }

    public function isOpen(): bool
    {
        return ! $this->resolution instanceof ReportResolution;
    }

    public function id(): ReportId
    {
        return $this->id;
    }

    public function postId(): PostId
    {
        return $this->postId;
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

    /**
     * @throws InvalidModerationRecordException
     */
    private function account(?string $id): ?string
    {
        return $id === null ? null : (AccountId::normalize($id) ?? throw InvalidModerationRecordException::invalidAccount($id));
    }
}
