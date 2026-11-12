<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Publications;

use App\Application\Publications\Contracts\PublicationSourceInterface;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use InvalidArgumentException;
use Modules\Forum\Application\Contracts\PublicationSyncQueueInterface;
use Modules\Forum\Application\Services\DefaultCategoriesInstaller;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Throwable;

/**
 * The daily catch-up (also run after any module is enabled): first creates the system categories
 * of sources that came after the forum («Mesas» and «Campañas» once a module offers game tables),
 * then queues a check of every publication its source lists as published and not
 * over (what was published before the module was enabled, or a change whose check was lost) and
 * of every one the forum still follows that the source no longer lists (taken down, over), and
 * of every campaign whose space still takes posts. The
 * checks read each publication again, so a listing that fails or comes back short never takes
 * anything down by itself: a type whose listing fails is skipped (and reported).
 */
final readonly class PublicationDiscussionReconciler
{
    public function __construct(
        private PublicationSourceRegistryInterface $publications,
        private SubjectDiscussionRepositoryInterface $records,
        private CategoryRepositoryInterface $categories,
        private PublicationSyncQueueInterface $queue,
        private DefaultCategoriesInstaller $installer,
    ) {}

    /**
     * @return int how many checks were queued
     */
    public function reconcile(): int
    {
        $this->installer->installSystemCategories();

        $queued = 0;

        foreach (array_keys(PublicationDiscussionSync::CATEGORIES) as $type) {
            $source = $this->publications->source($type);
            if (! $source instanceof PublicationSourceInterface) {
                continue;
            }

            try {
                $published = [];
                foreach ($source->publishedIds() as $id) {
                    $published[(string) $id] = true;
                }
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($this->records->liveIds($type) as $id) {
                $published[$id] = true;
            }

            foreach ($this->categories->writableSubjectIds($type) as $id) {
                $published[$id] = true;
            }

            foreach (array_keys($published) as $id) {
                $queued += $this->queue($type, (string) $id);
            }
        }

        return $queued;
    }

    private function queue(string $type, string $id): int
    {
        try {
            $this->queue->queue(new PublicationKey($type, $id));
        } catch (InvalidArgumentException) {
            return 0;
        }

        return 1;
    }
}
