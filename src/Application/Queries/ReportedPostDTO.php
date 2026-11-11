<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

use DateTimeImmutable;

/**
 * A post with open reports: how many, why (each reason once) and the notes people left.
 */
final readonly class ReportedPostDTO
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $postId,
        public ?int $postNumber,
        public string $status,
        public string $excerpt,
        public ?string $authorId,
        public string $discussionId,
        public int $discussionNumber,
        public string $discussionTitle,
        public string $categoryId,
        public int $reports,
        public array $reasons,
        public array $notes,
        public DateTimeImmutable $firstReportedAt,
    ) {}
}
