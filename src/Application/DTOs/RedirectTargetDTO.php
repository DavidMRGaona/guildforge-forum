<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\Entities\Discussion;

/**
 * Where an old address leads now: a discussion, at a post number (null: its start).
 */
final readonly class RedirectTargetDTO
{
    public function __construct(
        public Discussion $discussion,
        public ?int $postNumber,
    ) {}
}
