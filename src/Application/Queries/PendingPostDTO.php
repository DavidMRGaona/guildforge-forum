<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

use DateTimeImmutable;

/**
 * A post awaiting approval; `opensDiscussion` when its discussion waits with it.
 */
final readonly class PendingPostDTO
{
    public function __construct(
        public string $postId,
        public string $excerpt,
        public ?string $authorId,
        public string $discussionId,
        public int $discussionNumber,
        public string $discussionTitle,
        public string $categoryId,
        public bool $opensDiscussion,
        public DateTimeImmutable $writtenAt,
    ) {}
}
