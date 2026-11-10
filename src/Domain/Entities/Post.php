<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\ValueObjects\AccountId;
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
    private readonly ?string $authorId;

    /**
     * @param  string|null  $authorId  the account that wrote it; null once that account is gone
     * @param  DateTimeImmutable|null  $publishedAt  when it became readable: when it was written, or approved
     *
     * @throws InvalidPostException when the number or publication time does not match the status, or for an author id that is not a UUID
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
    ) {
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
    public static function publish(PostId $id, DiscussionId $discussionId, PostNumber $number, ?string $authorId, PostBody $body, DateTimeImmutable $createdAt): self
    {
        return new self($id, $discussionId, $number, $authorId, $body, PostStatus::Visible, $createdAt, $createdAt);
    }

    /**
     * A post that waits for a moderator's approval, without a number until then.
     *
     * @throws InvalidPostException
     */
    public static function submitForApproval(PostId $id, DiscussionId $discussionId, ?string $authorId, PostBody $body, DateTimeImmutable $createdAt): self
    {
        return new self($id, $discussionId, null, $authorId, $body, PostStatus::Pending, $createdAt, null);
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
     * Hidden by a moderator. A pending post is rejected instead (it never had a place).
     *
     * @throws InvalidPostException unless it is visible
     */
    public function hide(): void
    {
        $this->assertStatus([PostStatus::Visible], 'be hidden');

        $this->status = PostStatus::Hidden;
    }

    /**
     * @throws InvalidPostException unless a moderator hid it
     */
    public function restore(): void
    {
        $this->assertStatus([PostStatus::Hidden], 'be restored');

        $this->status = PostStatus::Visible;
    }

    /**
     * Deleted by its author: it keeps its number and readers see a placeholder. A pending post
     * is withdrawn instead (it never had a place).
     *
     * @throws InvalidPostException unless it is visible or hidden
     */
    public function deleteByAuthor(): void
    {
        $this->assertStatus([PostStatus::Visible, PostStatus::Hidden], 'be deleted by its author');

        $this->status = PostStatus::Deleted;
    }

    /**
     * @throws InvalidPostException once its author deleted it
     */
    public function edit(PostBody $body, DateTimeImmutable $at): void
    {
        $this->assertStatus([PostStatus::Visible, PostStatus::Hidden, PostStatus::Pending], 'be edited');

        $this->body = $body;
        $this->editedAt = $at;
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
