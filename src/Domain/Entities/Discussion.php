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
        private bool $closedBySystem = false,
        private ?int $number = null,
        private bool $awaitingApproval = false,
        private bool $titleSetByModeration = false,
    ) {
        if ($number !== null && $number < 1) {
            throw InvalidDiscussionException::invalidNumber($number);
        }

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

    /**
     * Gives it its public number (the "42" of its address), once, when it is first saved.
     *
     * @throws InvalidDiscussionException for a number below 1 or when it has one already
     */
    public function numberAs(int $number): void
    {
        if ($number < 1 || ($this->number !== null && $this->number !== $number)) {
            throw InvalidDiscussionException::invalidNumber($number);
        }

        $this->number = $number;
    }

    /**
     * Its public number; null until it is first saved.
     */
    public function number(): ?int
    {
        return $this->number;
    }

    /**
     * Renamed by the forum itself (its publication changed title). Whoever calls it checks first
     * that moderation did not give it its title (isTitleSetByModeration).
     */
    public function rename(DiscussionTitle $title): void
    {
        $this->title = $title;
    }

    /**
     * Renamed by a moderator: from now on the title is theirs and the forum leaves it alone.
     */
    public function renameByModeration(DiscussionTitle $title): void
    {
        $this->title = $title;
        $this->titleSetByModeration = true;
    }

    public function isTitleSetByModeration(): bool
    {
        return $this->titleSetByModeration;
    }

    /**
     * Its first post waits for approval: until a moderator approves it, only its author and its
     * moderators see the discussion.
     */
    public function awaitApproval(): void
    {
        $this->awaitingApproval = true;
    }

    /**
     * Its first post was approved: the discussion is there for everyone who reads its category.
     *
     * @throws InvalidDiscussionException unless it awaits approval
     */
    public function approve(): void
    {
        if (! $this->awaitingApproval) {
            throw InvalidDiscussionException::notAwaitingApproval($this->id->value);
        }

        $this->awaitingApproval = false;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->awaitingApproval;
    }

    public function moveTo(CategoryId $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    /**
     * Closed by someone (a moderator): the forum never opens it by itself.
     *
     * @throws InvalidDiscussionException when it is closed already
     */
    public function close(): void
    {
        if ($this->status === DiscussionStatus::Closed) {
            throw InvalidDiscussionException::alreadyClosed($this->id->value);
        }

        $this->status = DiscussionStatus::Closed;
        $this->closedBySystem = false;
    }

    /**
     * Closed by the forum itself (its publication was cancelled or taken down), so the forum may
     * open it again. Any later close or reopen by someone else takes that away.
     *
     * @throws InvalidDiscussionException when it is closed already
     */
    public function closeBySystem(): void
    {
        $this->close();
        $this->closedBySystem = true;
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
        $this->closedBySystem = false;
    }

    /**
     * Closed, and by the forum itself: the only kind of close the forum lifts.
     */
    public function isClosedBySystem(): bool
    {
        return $this->status === DiscussionStatus::Closed && $this->closedBySystem;
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
     * Open, shown and not awaiting approval. Who may still write in it (moderators in a closed one) is decided by the
     * forum's access rules, not here.
     */
    public function acceptsReplies(): bool
    {
        return $this->status === DiscussionStatus::Open && ! $this->hidden && ! $this->awaitingApproval;
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
