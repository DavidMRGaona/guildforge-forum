<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PublicationKey;

/**
 * The automatic discussion of a publication, and what the forum last said about it.
 *
 * - Its facts are null until the publication got a discussion. Once it had one they stay, even
 *   if a moderator deletes the discussion: then it is never opened again.
 * - Whether the forum closed the discussion itself is the discussion's own business
 *   (Discussion::closeBySystem()), so a moderator closing or opening it clears it.
 */
final class SubjectDiscussion
{
    /**
     * @param  array<string, scalar|null>|null  $facts
     */
    public function __construct(
        private readonly PublicationKey $key,
        private ?DiscussionId $discussionId = null,
        private ?PostId $firstPostId = null,
        private ?array $facts = null,
        private bool $live = false,
        private ?DateTimeImmutable $nextCheckAt = null,
        private ?bool $spaceOpen = null,
    ) {}

    /**
     * Its discussion was opened with this first post.
     *
     * @param  array<string, scalar|null>  $facts
     */
    public function announce(DiscussionId $discussionId, PostId $firstPostId, array $facts, bool $live): void
    {
        $this->discussionId = $discussionId;
        $this->firstPostId = $firstPostId;
        $this->facts = $facts;
        $this->live = $live;
    }

    /**
     * What the forum knows now about the publication.
     *
     * @param  array<string, scalar|null>  $facts
     */
    public function record(array $facts, bool $live): void
    {
        $this->facts = $facts;
        $this->live = $live;
    }

    /**
     * Whether the publication was active when its space (a campaign's) was last checked.
     */
    public function spaceSeenOpen(bool $open): void
    {
        $this->spaceOpen = $open;
    }

    public function spaceOpen(): ?bool
    {
        return $this->spaceOpen;
    }

    public function checkAt(?DateTimeImmutable $at): void
    {
        $this->nextCheckAt = $at;
    }

    public function key(): PublicationKey
    {
        return $this->key;
    }

    public function discussionId(): ?DiscussionId
    {
        return $this->discussionId;
    }

    public function firstPostId(): ?PostId
    {
        return $this->firstPostId;
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function facts(): ?array
    {
        return $this->facts;
    }

    /**
     * Whether the publication ever had a discussion (whether or not it is still there).
     */
    public function wasAnnounced(): bool
    {
        return $this->facts !== null;
    }

    /**
     * Published and not over: the forum still follows it.
     */
    public function isLive(): bool
    {
        return $this->live;
    }

    public function nextCheckAt(): ?DateTimeImmutable
    {
        return $this->nextCheckAt;
    }
}
