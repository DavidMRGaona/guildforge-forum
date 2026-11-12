<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * A number a discussion gave out to a post that moderation moved away, and where that post is
 * now (nulls once it is gone).
 */
final readonly class MovedPostRowDTO
{
    public function __construct(
        public int $fromNumber,
        public ?string $discussionId,
        public ?int $number,
    ) {}
}
