<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Events;

/**
 * Moderation lifted someone's suspension before its end (after the commit), so the notices can
 * tell them they may write again. Nothing is announced for a suspension that ended by itself or
 * was replaced by a newer one.
 */
final readonly class ForumUserSuspensionLifted
{
    public function __construct(
        public string $suspensionId,
        public string $userId,
        public ?string $liftedBy,
    ) {}
}
