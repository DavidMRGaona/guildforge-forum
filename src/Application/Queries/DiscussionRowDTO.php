<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

use DateTimeImmutable;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;

/**
 * One discussion of a list, as stored. `lastReadNumber` is null without a reader and 0 when the
 * reader never opened it.
 */
final readonly class DiscussionRowDTO
{
    /**
     * @param  list<DiscussionTopic>  $topics
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $title,
        public string $categoryId,
        public ?string $authorId,
        public DiscussionStatus $status,
        public bool $pinned,
        public bool $hidden,
        public int $postCount,
        public ?int $lastPostNumber,
        public ?string $lastPostAuthorId,
        public DateTimeImmutable $lastActivityAt,
        public DateTimeImmutable $createdAt,
        public array $topics,
        public ?int $lastReadNumber,
        public ?string $lastPostSharedName = null,
    ) {}

    /**
     * Whether the reader has posts left to read in it; false without a reader.
     */
    public function isUnread(): bool
    {
        return $this->lastReadNumber !== null && $this->lastPostNumber !== null && $this->lastReadNumber < $this->lastPostNumber;
    }
}
