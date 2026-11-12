<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Listeners;

use App\Domain\Modules\Events\ModuleEnabled;
use App\Domain\Modules\Events\ModuleUpdated;
use Modules\Forum\Infrastructure\Jobs\SyncPublicationsAfterModuleChangeJob;

/**
 * A module enabled or updated may bring publication sources the forum builds on (game tables,
 * campaigns): their categories and discussions come with the reconciliation, run at once in a new
 * process instead of waiting for the daily one. Which module it was does not matter; the
 * reconciliation asks the core's registry. Disabling needs nothing: what depends on a missing source
 * is hidden as soon as it is missing (PublicationAvailability).
 */
final class SyncPublicationsOnModuleChange
{
    public function handle(ModuleEnabled|ModuleUpdated $event): void
    {
        SyncPublicationsAfterModuleChangeJob::dispatchSafely();
    }
}
