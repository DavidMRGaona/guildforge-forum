<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PostNumber;

/**
 * A message in a discussion. From the moment it is readable (published, or approved) it has a
 * number and a publication time, and keeps both when hidden or deleted, so the numbering
 * readers see has no gaps; a post awaiting approval has neither. Numbers and publication times
 * are given out in the same order.
 */
final class Post
{
    public const int MAX_HIDDEN_REASON_LENGTH = 300;

    private readonly ?string $authorId;

    /**
     * @param  string|null  $authorId  the account that wrote it; null once that account is gone
     * @param  DateTimeImmutable|null  $publishedAt  when it became readable: when it was written, or approved
     * @param  string|null  $hiddenReason  why moderation hid it, shown to its author and its moderators
     * @param  CharacterId|null  $characterId  the character it speaks as; null out of character
     *
     * @throws InvalidPostException when the number or publication time does not match the status, for an author id that is not a UUID, or for a reason on a post that is not hidden
     */
    public function __construct(
        private readonly PostId $id,
        private readonly DiscussionId $discussionId,
        private ?PostNumber $number,
        ?string $authorId,
        private PostBody $body,
        private PostStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $publishedAt,
        private ?DateTimeImmutable $editedAt = null,
        private ?DateTimeImmutable $deletedAt = null,
        private ?DateTimeImmutable $contentPurgedAt = null,
        private ?string $hiddenReason = null,
        private ?CharacterId $characterId = null,
    ) {
        if ($hiddenReason !== null && $status !== PostStatus::Hidden) {
            throw InvalidPostException::reasonWithoutHiding($status);
        }

        if ($status->isNumbered() !== ($number instanceof PostNumber) || $status->isNumbered() !== ($publishedAt instanceof DateTimeImmutable)) {
            throw InvalidPostException::numberMismatch($status);
        }

        $this->authorId = $authorId === null
            ? null
            : (AccountId::normalize($authorId) ?? throw InvalidPostException::invalidAuthor($authorId));
    }

    /**
     * A post readable at once, with the number it takes in its discussion.
     *
     * @throws InvalidPostException
     */
    public static function publish(PostId $id, DiscussionId $discussionId, PostNumber $number, ?string $authorId, PostBody $body, DateTimeImmutable $createdAt, ?CharacterId $characterId = null): self
    {
        return new self($id, $discussionId, $number, $authorId, $body, PostStatus::Visible, $createdAt, $createdAt, characterId: $characterId);
    }

    /**
     * A post that waits for a moderator's approval, without a number until then.
     *
     * @throws InvalidPostException
     */
    public static function submitForApproval(PostId $id, DiscussionId $discussionId, ?string $authorId, PostBody $body, DateTimeImmutable $createdAt, ?CharacterId $characterId = null): self
    {
        return new self($id, $discussionId, null, $authorId, $body, PostStatus::Pending, $createdAt, null, characterId: $characterId);
    }

    /**
     * Readable from now on, after every post published while it waited.
     *
     * @throws InvalidPostException unless it is awaiting approval
     */
    public function approve(PostNumber $number, DateTimeImmutable $at): void
    {
        $this->assertStatus([PostStatus::Pending], 'be approved');

        $this->number = $number;
        $this->publishedAt = $at;
        $this->status = PostStatus::Visible;
    }

    /**
     * Hidden by a moderator, with the reason its author and moderators read (blank means none).
     * A pending post is rejected instead (it never had a place).
     *
     * @throws InvalidPostException unless it is visible, or for a reason too long
     */
    public function hide(?string $reason = null): void
    {
        $this->assertStatus([PostStatus::Visible], 'be hidden');

        $reason = $reason === null ? '' : trim($reason);
        if (mb_strlen($reason) > self::MAX_HIDDEN_REASON_LENGTH) {
            throw InvalidPostException::hiddenReasonTooLong(self::MAX_HIDDEN_REASON_LENGTH);
        }

        $this->status = PostStatus::Hidden;
        $this->hiddenReason = $reason === '' ? null : $reason;
    }

    /**
     * Shown again: the reason it was hidden goes with it.
     *
     * @throws InvalidPostException unless a moderator hid it
     */
    public function restore(): void
    {
        $this->assertStatus([PostStatus::Hidden], 'be restored');

        $this->status = PostStatus::Visible;
        $this->hiddenReason = null;
    }

    /** Why moderation hid it; null unless hidden with a reason */
    public function hiddenReason(): ?string
    {
        return $this->hiddenReason;
    }

    /**
     * Deleted by its author: it keeps its number and readers see a placeholder. Its moderators
     * still read it until its content is purged (DeletedPostRetention). A pending post is
     * withdrawn instead (it never had a place).
     *
     * @throws InvalidPostException unless it is visible or hidden
     */
    public function deleteByAuthor(DateTimeImmutable $at): void
    {
        $this->assertStatus([PostStatus::Visible, PostStatus::Hidden], 'be deleted by its author');

        $this->status = PostStatus::Deleted;
        $this->deletedAt = $at;
    }

    /**
     * Empties a post its author deleted: only its place in the numbering is left.
     *
     * @throws InvalidPostException unless its author deleted it
     */
    public function purgeContent(DateTimeImmutable $at): void
    {
        $this->assertStatus([PostStatus::Deleted], 'be purged');

        $this->body = PostBody::empty();
        $this->contentPurgedAt = $at;
    }

    /**
     * New words, and the character they are said as (null out of character).
     *
     * @throws InvalidPostException once its author deleted it
     */
    public function edit(PostBody $body, DateTimeImmutable $at, ?CharacterId $characterId): void
    {
        $this->assertStatus([PostStatus::Visible, PostStatus::Hidden, PostStatus::Pending], 'be edited');

        $this->body = $body;
        $this->characterId = $characterId;
        $this->editedAt = $at;
    }

    /** The character it speaks as; null out of character (or once that character was deleted) */
    public function characterId(): ?CharacterId
    {
        return $this->characterId;
    }

    public function speaksAs(?CharacterId $characterId): bool
    {
        return $characterId instanceof CharacterId
            ? $this->characterId?->equals($characterId) === true
            : ! $this->characterId instanceof CharacterId;
    }

    public function isVisible(): bool
    {
        return $this->status === PostStatus::Visible;
    }

    public function id(): PostId
    {
        return $this->id;
    }

    public function discussionId(): DiscussionId
    {
        return $this->discussionId;
    }

    public function number(): ?PostNumber
    {
        return $this->number;
    }

    public function authorId(): ?string
    {
        return $this->authorId;
    }

    public function body(): PostBody
    {
        return $this->body;
    }

    public function status(): PostStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function editedAt(): ?DateTimeImmutable
    {
        return $this->editedAt;
    }

    /** When its author deleted it; null unless deleted (or deleted before this was kept) */
    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isContentPurged(): bool
    {
        return $this->contentPurgedAt instanceof DateTimeImmutable;
    }

    public function contentPurgedAt(): ?DateTimeImmutable
    {
        return $this->contentPurgedAt;
    }

    /**
     * @param  list<PostStatus>  $allowed
     *
     * @throws InvalidPostException
     */
    private function assertStatus(array $allowed, string $action): void
    {
        if (! in_array($this->status, $allowed, true)) {
            throw InvalidPostException::cannotChange($this->id->value, $this->status, $action);
        }
    }
}
