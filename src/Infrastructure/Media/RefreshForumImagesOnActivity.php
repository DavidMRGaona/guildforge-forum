<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Media;

use App\Application\Media\Services\MediaIndexerInterface;
use App\Domain\Activity\Events\ActivityRecorded;
use Illuminate\Support\Facades\Log;
use Modules\Forum\Infrastructure\Activity\PostActivitySource;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CharacterModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Throwable;

/**
 * Keeps the media library in line with who reads each post (forum#155). The forum already tells the
 * activity contract about every post whose readers may have changed (ForumActivityObserver: approving,
 * hiding, showing, deleting, moving, splitting, merging, a category's audience, «Acceso al foro»), so
 * the same announcement refreshes the post's images and its character's portrait, after the commit.
 *
 * Only a post whose documents name an image or an embed is refreshed, and one that is gone is
 * refreshed anyway (deleting a discussion takes its posts without their own events), so its usages
 * go. Each character is refreshed once per request or job: a category or «Acceso al foro» publishes
 * every post again, and one character may speak in many of them. The refresh reads the tables when it
 * runs; what a later write in the same request changes is settled by the nightly sync.
 *
 * Bound scoped, so what it remembers never outlives a request or a job. Never fails: a failure is
 * logged and the nightly sync settles the record.
 */
final class RefreshForumImagesOnActivity
{
    /** @var array<string, true> */
    private array $refreshedCharacters = [];

    public function __construct(
        private readonly MediaIndexerInterface $indexer,
        private readonly PostImageMediaSource $posts,
    ) {}

    public function handle(ActivityRecorded $event): void
    {
        if ($event->sourceType !== PostActivitySource::TYPE) {
            return;
        }

        try {
            $post = PostModel::query()->select(['id', 'character_id'])->find($event->subjectId);

            if (! $post instanceof PostModel || $this->posts->mayHoldImages($post->id)) {
                $this->indexer->refresh(PostImageMediaSource::TYPE, $event->subjectId);
            }

            if ($post instanceof PostModel && $post->character_id !== null) {
                $this->character($post->character_id);
            }
        } catch (Throwable $exception) {
            Log::warning('[Forum] Could not refresh the media library after a post changed.', ['exception' => $exception::class]);
        }
    }

    /**
     * A character whose portrait may have started or stopped being seen, once per request or job.
     */
    public function character(string $characterId): void
    {
        if (isset($this->refreshedCharacters[$characterId])) {
            return;
        }

        $this->refreshedCharacters[$characterId] = true;

        if (CharacterModel::query()->whereKey($characterId)->whereNotNull('portrait_path')->exists()) {
            $this->indexer->refresh(CharacterPortraitMediaSource::TYPE, $characterId);
        }
    }
}
