<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Events;

use DateTimeImmutable;

/**
 * Someone was suspended in the forum (after the commit), so the notices can tell them why and
 * until when (null: until it is lifted).
 */
final readonly class ForumUserSuspended
{
    public function __construct(
        public string $suspensionId,
        public string $userId,
        public string $reason,
        public ?DateTimeImmutable $endsAt,
    ) {}
}
