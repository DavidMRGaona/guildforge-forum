<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\SuspensionId;

/**
 * Someone kept from writing in the forum for a while, or with no end: they still read, but do
 * not post, like or report. It ends by itself when its time comes (nothing has to run), or
 * earlier when a moderator lifts it.
 */
final class Suspension
{
    public const int MAX_REASON_LENGTH = 500;

    private readonly string $userId;

    private readonly ?string $suspendedBy;

    private readonly string $reason;

    private ?string $liftedBy;

    /**
     * @param  DateTimeImmutable|null  $endsAt  null: until someone lifts it
     *
     * @throws InvalidModerationRecordException
     */
    public function __construct(
        private readonly SuspensionId $id,
        string $userId,
        ?string $suspendedBy,
        string $reason,
        private readonly DateTimeImmutable $startsAt,
        private readonly ?DateTimeImmutable $endsAt,
        private ?DateTimeImmutable $liftedAt = null,
        ?string $liftedBy = null,
    ) {
        $this->userId = AccountId::normalize($userId) ?? throw InvalidModerationRecordException::invalidAccount($userId);
        $this->suspendedBy = self::account($suspendedBy);
        $this->liftedBy = self::account($liftedBy);

        $reason = trim($reason);
        if ($reason === '') {
            throw InvalidModerationRecordException::blankReason();
        }
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw InvalidModerationRecordException::reasonTooLong(self::MAX_REASON_LENGTH);
        }
        $this->reason = $reason;

        if ($endsAt instanceof DateTimeImmutable && $endsAt <= $startsAt) {
            throw InvalidModerationRecordException::endsBeforeItStarts();
        }
    }

    /**
     * Whether it keeps the person from writing at that moment.
     */
    public function isActiveAt(DateTimeImmutable $at): bool
    {
        return ! $this->liftedAt instanceof DateTimeImmutable
            && $this->startsAt <= $at
            && (! $this->endsAt instanceof DateTimeImmutable || $at < $this->endsAt);
    }

    /**
     * Lifted by a moderator before its end.
     *
     * @throws InvalidModerationRecordException when it was lifted already
     */
    public function lift(?string $moderatorId, DateTimeImmutable $at): void
    {
        if ($this->liftedAt instanceof DateTimeImmutable) {
            throw InvalidModerationRecordException::alreadyLifted($this->id->value);
        }

        $this->liftedAt = $at;
        $this->liftedBy = self::account($moderatorId);
    }

    public function id(): SuspensionId
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function suspendedBy(): ?string
    {
        return $this->suspendedBy;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function startsAt(): DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function liftedAt(): ?DateTimeImmutable
    {
        return $this->liftedAt;
    }

    public function liftedBy(): ?string
    {
        return $this->liftedBy;
    }

    /**
     * @throws InvalidModerationRecordException
     */
    private static function account(?string $id): ?string
    {
        return $id === null ? null : (AccountId::normalize($id) ?? throw InvalidModerationRecordException::invalidAccount($id));
    }
}
