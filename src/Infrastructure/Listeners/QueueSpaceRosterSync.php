<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Listeners;

use App\Domain\Publications\Events\PublicationRosterChanged;
use Modules\Forum\Application\Publications\CampaignSpaceSync;
use Modules\Forum\Infrastructure\Jobs\SyncSpaceRosterJob;
use Throwable;

/**
 * Queues the follows of a campaign's space when its roster changes. Changing a roster never
 * fails because of the forum: anything that goes wrong here is reported and swallowed.
 */
final readonly class QueueSpaceRosterSync
{
    public function handle(PublicationRosterChanged $event): void
    {
        if ($event->type !== CampaignSpaceSync::TYPE) {
            return;
        }

        try {
            SyncSpaceRosterJob::dispatch($event->type, $event->id, $event->userIds)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
