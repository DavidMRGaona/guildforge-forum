<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Forum\Application\Publications\PublicationDiscussionSync;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Throwable;

/**
 * Brings one publication's discussion in step with the publication as its source gives it when
 * the job runs (never what was known when it was queued). A source that fails makes the job
 * fail and be retried later: it is never taken for a publication taken down.
 */
final class SyncPublicationDiscussionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly bool $removed = false,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(PublicationDiscussionSync $sync): void
    {
        $sync->sync(new PublicationKey($this->type, $this->id), $this->removed);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
