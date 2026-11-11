<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Listeners;

use App\Domain\Publications\Events\PublicationChanged;
use App\Domain\Publications\Events\PublicationRemoved;
use InvalidArgumentException;
use Modules\Forum\Application\Contracts\PublicationSyncQueueInterface;
use Modules\Forum\Application\Publications\PublicationDiscussionSync;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Throwable;

/**
 * Queues a check of the discussion whenever a publication the forum announces changes or is
 * deleted. Publishing on the site never fails because of the forum: anything that goes wrong
 * here is reported and swallowed (the daily reconciliation catches up).
 */
final readonly class QueuePublicationDiscussionSync
{
    public function __construct(
        private PublicationSyncQueueInterface $queue,
    ) {}

    public function handle(PublicationChanged|PublicationRemoved $event): void
    {
        if (! PublicationDiscussionSync::announces($event->type)) {
            return;
        }

        try {
            $this->queue->queue(new PublicationKey($event->type, $event->id), $event instanceof PublicationRemoved);
        } catch (InvalidArgumentException) {
            // An id no source would give: nothing to announce
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
