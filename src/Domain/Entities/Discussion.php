<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Exceptions\InvalidDiscussionException;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;

/**
 * A topic: a title in a category, and its posts. Its counters (posts, last post, last activity)
 * are kept by the persistence layer with every write, not here.
 */
final class Discussion
{
    private readonly ?string $authorId;

    /**
     * @param  string|null  $authorId  the account that started it; null once that account is gone
     *
     * @throws InvalidDiscussionException for an author id that is not a UUID
     */
    public function __construct(
        private readonly DiscussionId $id,
        private CategoryId $categoryId,
        ?string $authorId,
        private DiscussionTitle $title,
        private DiscussionStatus $status,
        private bool $pinned,
        private bool $hidden,
        private readonly DateTimeImmutable $createdAt,
    ) {
        $this->authorId = $authorId === null
            ? null
            : (AccountId::normalize($authorId) ?? throw InvalidDiscussionException::invalidAuthor($authorId));
    }

    /**
     * A new discussion: open, not pinned and shown.
     *
     * @throws InvalidDiscussionException
     */
    public static function start(DiscussionId $id, CategoryId $categoryId, ?string $authorId, DiscussionTitle $title, DateTimeImmutable $createdAt): self
    {
        return new self($id, $categoryId, $authorId, $title, DiscussionStatus::Open, pinned: false, hidden: false, createdAt: $createdAt);
    }

    public function rename(DiscussionTitle $title): void
    {
        $this->title = $title;
    }

    public function moveTo(CategoryId $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    /**
     * @throws InvalidDiscussionException when it is closed already
     */
    public function close(): void
    {
        if ($this->status === DiscussionStatus::Closed) {
            throw InvalidDiscussionException::alreadyClosed($this->id->value);
        }

        $this->status = DiscussionStatus::Closed;
    }

    /**
     * @throws InvalidDiscussionException when it is open already
     */
    public function reopen(): void
    {
        if ($this->status === DiscussionStatus::Open) {
            throw InvalidDiscussionException::alreadyOpen($this->id->value);
        }

        $this->status = DiscussionStatus::Open;
    }

    public function pin(): void
    {
        $this->pinned = true;
    }

    public function unpin(): void
    {
        $this->pinned = false;
    }

    public function hide(): void
    {
        $this->hidden = true;
    }

    public function unhide(): void
    {
        $this->hidden = false;
    }

    /**
     * Open and shown. Who may still write in it (moderators in a closed one) is decided by the
     * forum's access rules, not here.
     */
    public function acceptsReplies(): bool
    {
        return $this->status === DiscussionStatus::Open && ! $this->hidden;
    }

    public function id(): DiscussionId
    {
        return $this->id;
    }

    public function categoryId(): CategoryId
    {
        return $this->categoryId;
    }

    public function authorId(): ?string
    {
        return $this->authorId;
    }

    public function title(): DiscussionTitle
    {
        return $this->title;
    }

    public function status(): DiscussionStatus
    {
        return $this->status;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
