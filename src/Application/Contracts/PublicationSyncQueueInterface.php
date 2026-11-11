<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use DateTimeImmutable;
use Modules\Forum\Domain\ValueObjects\PublicationKey;

/**
 * Queues a check of a publication's discussion: the check reads the publication again from its
 * source when it runs, never what was known when it was queued.
 */
interface PublicationSyncQueueInterface
{
    /**
     * @param  bool  $removed  the owner said it was deleted (only changes how its absence is told)
     */
    public function queue(PublicationKey $key, bool $removed = false): void;

    /**
     * A check that runs no earlier than the given time (registrations that close at a set time).
     */
    public function queueAt(PublicationKey $key, DateTimeImmutable $at): void;
}
