<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use DateTimeImmutable;
use Modules\Forum\Domain\ValueObjects\PostId;

final readonly class DiscussionCountersDTO
{
    public function __construct(
        public int $postCount,
        public ?PostId $lastPostId,
        public DateTimeImmutable $lastActivityAt,
    ) {}
}
