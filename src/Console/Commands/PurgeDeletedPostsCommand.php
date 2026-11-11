<?php

declare(strict_types=1);

namespace Modules\Forum\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Forum\Application\Services\DeletedPostPurger;

/**
 * Daily: empties the posts their authors deleted more than 30 days ago.
 */
final class PurgeDeletedPostsCommand extends Command
{
    protected $signature = 'forum:purge-deleted-posts';

    protected $description = 'Empty the forum posts their authors deleted more than 30 days ago';

    public function handle(DeletedPostPurger $purger): int
    {
        $purged = $purger->purge(CarbonImmutable::now());
        $this->info("Purged {$purged} deleted posts.");

        return self::SUCCESS;
    }
}
