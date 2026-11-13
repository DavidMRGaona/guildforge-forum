<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Enums\ConversationEntryKind;
use Modules\Forum\Domain\Exceptions\InvalidConversationException;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Domain\ValueObjects\PostBody;

/**
 * One entry of a private conversation: a message, or the note that someone was added or left.
 *
 * A message is never edited. Its author may delete it and moderation may hide it once it is
 * reported; either way its text is gone for good (what was reported is copied with the report).
 */
final class ConversationMessage
{
    public const int MAX_REASON_LENGTH = 500;

    private readonly ?string $authorId;

    private readonly ?string $subjectUserId;

    private ?string $hiddenReason;

    /**
     * @throws InvalidConversationException for a hiding reason too long
     */
    public function __construct(
        private readonly ConversationMessageId $id,
        private readonly ConversationId $conversationId,
        private readonly int $number,
        private readonly ConversationEntryKind $kind,
        ?string $authorId,
        private PostBody $body,
        private readonly DateTimeImmutable $createdAt,
        ?string $subjectUserId = null,
        private ?DateTimeImmutable $deletedAt = null,
        private ?DateTimeImmutable $hiddenAt = null,
        ?string $hiddenReason = null,
    ) {
        $this->authorId = $authorId === null ? null : AccountId::normalize($authorId);
        $this->subjectUserId = $subjectUserId === null ? null : AccountId::normalize($subjectUserId);
        $this->hiddenReason = $this->reason($hiddenReason);
    }

    public static function message(ConversationMessageId $id, ConversationId $conversationId, int $number, string $authorId, PostBody $body, DateTimeImmutable $at): self
    {
        return new self($id, $conversationId, $number, ConversationEntryKind::Message, $authorId, $body, $at);
    }

    public static function added(ConversationMessageId $id, ConversationId $conversationId, int $number, string $adderId, string $addedId, DateTimeImmutable $at): self
    {
        return new self($id, $conversationId, $number, ConversationEntryKind::Added, $adderId, PostBody::empty(), $at, $addedId);
    }

    public static function left(ConversationMessageId $id, ConversationId $conversationId, int $number, string $leaverId, DateTimeImmutable $at): self
    {
        return new self($id, $conversationId, $number, ConversationEntryKind::Left, $leaverId, PostBody::empty(), $at);
    }

    /**
     * Its author deleted it: the text goes, «Mensaje borrado» stays in its place.
     *
     * @throws InvalidConversationException when it is not a message shown
     */
    public function deleteByAuthor(DateTimeImmutable $at): void
    {
        $this->assertShownMessage();

        $this->body = PostBody::empty();
        $this->deletedAt = $at;
    }

    /**
     * Moderation hid it after a report: the text goes, with the reason for its author.
     *
     * @throws InvalidConversationException when it is not a message shown, or the reason is too long
     */
    public function hide(?string $reason, DateTimeImmutable $at): void
    {
        $this->assertShownMessage();

        $this->hiddenReason = $this->reason($reason);
        $this->body = PostBody::empty();
        $this->hiddenAt = $at;
    }

    public function isMessage(): bool
    {
        return $this->kind === ConversationEntryKind::Message;
    }

    /**
     * A message with its text: neither deleted by its author nor hidden by moderation.
     */
    public function isShown(): bool
    {
        return $this->isMessage() && ! $this->deletedAt instanceof DateTimeImmutable && ! $this->hiddenAt instanceof DateTimeImmutable;
    }

    public function id(): ConversationMessageId
    {
        return $this->id;
    }

    public function conversationId(): ConversationId
    {
        return $this->conversationId;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function kind(): ConversationEntryKind
    {
        return $this->kind;
    }

    public function authorId(): ?string
    {
        return $this->authorId;
    }

    public function subjectUserId(): ?string
    {
        return $this->subjectUserId;
    }

    public function body(): PostBody
    {
        return $this->body;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function hiddenAt(): ?DateTimeImmutable
    {
        return $this->hiddenAt;
    }

    public function hiddenReason(): ?string
    {
        return $this->hiddenReason;
    }

    /**
     * @throws InvalidConversationException
     */
    private function assertShownMessage(): void
    {
        if (! $this->isMessage()) {
            throw InvalidConversationException::notAMessage($this->id->value);
        }
        if (! $this->isShown()) {
            throw InvalidConversationException::notShown($this->id->value);
        }
    }

    /**
     * @throws InvalidConversationException
     */
    private function reason(?string $reason): ?string
    {
        $reason = $reason === null ? '' : trim($reason);
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw InvalidConversationException::reasonTooLong(self::MAX_REASON_LENGTH);
        }

        return $reason === '' ? null : $reason;
    }
}
