<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Forum\Application\Publications\SpaceRosterFollows;
use Throwable;

/**
 * Follows (or stops following) a campaign's space for the accounts whose place in its roster
 * changed, with the roster as it is when the job runs. A roster that cannot be read fails the job
 * and it is retried.
 */
final class SyncSpaceRosterJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /**
     * @param  list<string>  $accountIds
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly array $accountIds,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(SpaceRosterFollows $follows): void
    {
        $follows->sync($this->type, $this->id, $this->accountIds);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
