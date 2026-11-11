<?php

declare(strict_types=1);

namespace Modules\Forum\Console\Commands;

use Illuminate\Console\Command;
use Modules\Forum\Application\Publications\PublicationDiscussionReconciler;

final class SyncPublicationDiscussionsCommand extends Command
{
    protected $signature = 'forum:sync-publications';

    protected $description = 'Queue a check of the discussion of every publication (game tables, campaigns, events) the forum announces';

    public function handle(PublicationDiscussionReconciler $reconciler): int
    {
        $this->info(sprintf('Queued %d publication checks.', $reconciler->reconcile()));

        return self::SUCCESS;
    }
}
