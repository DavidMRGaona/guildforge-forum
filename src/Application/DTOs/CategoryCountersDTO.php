<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use DateTimeImmutable;
use Modules\Forum\Domain\ValueObjects\PostId;

final readonly class CategoryCountersDTO
{
    public function __construct(
        public int $discussionCount,
        public int $postCount,
        public ?PostId $lastPostId,
        public ?DateTimeImmutable $lastActivityAt,
    ) {}
}
