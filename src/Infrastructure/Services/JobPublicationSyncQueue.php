<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use DateTimeImmutable;
use Modules\Forum\Application\Contracts\PublicationSyncQueueInterface;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Infrastructure\Jobs\SyncPublicationDiscussionJob;

final class JobPublicationSyncQueue implements PublicationSyncQueueInterface
{
    public function queue(PublicationKey $key, bool $removed = false): void
    {
        SyncPublicationDiscussionJob::dispatch($key->type, $key->id, $removed)->afterCommit();
    }

    public function queueAt(PublicationKey $key, DateTimeImmutable $at): void
    {
        SyncPublicationDiscussionJob::dispatch($key->type, $key->id)->delay($at)->afterCommit();
    }
}
