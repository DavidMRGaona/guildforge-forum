<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Listeners;

use App\Domain\Events\UserAnonymized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Forum\Application\Services\AccountErasure;

/**
 * Queued: the work repeats safely, so a failed attempt is tried again.
 */
final class ForgetAnonymizedAccount implements ShouldQueue
{
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        private readonly AccountErasure $erasure,
    ) {}

    public function handle(UserAnonymized $event): void
    {
        $this->erasure->forget($event->userId);
    }
}
