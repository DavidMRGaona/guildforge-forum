<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Throwable;

/**
 * What an author deleted is kept for moderators for a while (reports may need it) and then
 * emptied: body and revisions go, the post keeps only its place in the numbering, and its
 * images stop counting as used.
 */
final readonly class DeletedPostPurger
{
    public const int RETENTION_DAYS = 30;

    private const int BATCH = 200;

    public function __construct(
        private PostRepositoryInterface $posts,
        private ForumWriter $writer,
    ) {}

    /**
     * Purges every post deleted more than RETENTION_DAYS before `$now`. A post that fails is
     * logged and left for the next run.
     *
     * @return int how many were purged
     */
    public function purge(DateTimeImmutable $now): int
    {
        $cutoff = $now->sub(new DateInterval('P'.self::RETENTION_DAYS.'D'));
        $purged = 0;
        /** @var array<string, true> $failed */
        $failed = [];

        do {
            $batch = array_values(array_filter(
                $this->posts->deletedAwaitingPurge($cutoff, self::BATCH + count($failed)),
                static fn ($id): bool => ! isset($failed[$id->value]),
            ));

            foreach ($batch as $id) {
                try {
                    $this->writer->purgeDeletedPost($id, $now);
                    $purged++;
                } catch (Throwable $exception) {
                    $failed[$id->value] = true;
                    Log::warning('Forum: a deleted post could not be purged.', ['post' => $id->value, 'exception' => $exception->getMessage()]);
                }
            }
        } while ($batch !== []);

        return $purged;
    }
}
