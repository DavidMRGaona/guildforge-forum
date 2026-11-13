<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

use DateTimeImmutable;

/**
 * What a category counts (its own discussions, not its subcategories') and its last post. A last
 * post that speaks as a shared character names it (lastPostSharedName) and no account.
 */
final readonly class CategoryStatsDTO
{
    public function __construct(
        public int $discussionCount,
        public int $postCount,
        public ?DateTimeImmutable $lastActivityAt = null,
        public ?int $lastDiscussionNumber = null,
        public ?string $lastDiscussionTitle = null,
        public ?int $lastPostNumber = null,
        public ?string $lastPostAuthorId = null,
        public ?string $lastPostSharedName = null,
    ) {}
}
