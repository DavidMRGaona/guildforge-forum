<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

use DateTimeImmutable;

/**
 * One of a person's latest messages, as the profile lists it: where it is and what it says.
 */
final readonly class ProfilePostRowDTO
{
    /**
     * @param  array<array-key, mixed>  $document  the stored body
     */
    public function __construct(
        public int $number,
        public int $discussionNumber,
        public string $discussionTitle,
        public string $categoryId,
        public DateTimeImmutable $publishedAt,
        public array $document,
    ) {}
}
