<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Enums\ConversationEntryKind;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\ConversationTitle;

/**
 * A private conversation: its title and how far its numbering went. Who is in it, and how far
 * each person read, is kept apart (ConversationParticipantsInterface). Entries are numbered from
 * 1 in the order they were written; only messages make it unread.
 */
final class Conversation
{
    public function __construct(
        private readonly ConversationId $id,
        private readonly ConversationTitle $title,
        private readonly DateTimeImmutable $createdAt,
        private int $lastNumber = 0,
        private int $lastMessageNumber = 0,
        private ?DateTimeImmutable $lastActivityAt = null,
    ) {
        $this->lastActivityAt ??= $createdAt;
    }

    public static function start(ConversationId $id, ConversationTitle $title, DateTimeImmutable $at): self
    {
        return new self($id, $title, $at);
    }

    /**
     * Takes the next number for an entry of this kind written at that moment. Callers hold the
     * conversation locked, so two entries never share a number.
     */
    public function takeNumber(ConversationEntryKind $kind, DateTimeImmutable $at): int
    {
        $this->lastNumber++;
        if ($kind === ConversationEntryKind::Message) {
            $this->lastMessageNumber = $this->lastNumber;
            $this->lastActivityAt = $at;
        }

        return $this->lastNumber;
    }

    public function id(): ConversationId
    {
        return $this->id;
    }

    public function title(): ConversationTitle
    {
        return $this->title;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastNumber(): int
    {
        return $this->lastNumber;
    }

    public function lastMessageNumber(): int
    {
        return $this->lastMessageNumber;
    }

    /**
     * When its last message was written (or when it started).
     */
    public function lastActivityAt(): DateTimeImmutable
    {
        return $this->lastActivityAt ?? $this->createdAt;
    }
}
