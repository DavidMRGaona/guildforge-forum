<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Jobs;

use App\Application\Activity\Services\ActivityPublisherInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Modules\Forum\Infrastructure\Activity\DiscussionActivitySource;
use Modules\Forum\Infrastructure\Activity\PostActivitySource;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;

/**
 * Publishes again, in batches, the activity of whatever depends on one discussion (its posts), one
 * category (its discussions, its subcategories' ones and all their posts) or the whole forum (its
 * access changed): whether each counts is decided when the consumer reads it, from the tables as
 * they are then (forum#151). Publishing one that did not change is harmless.
 */
final class PublishForumActivityJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string DISCUSSION = 'discussion';

    public const string CATEGORY = 'category';

    public const string EVERYTHING = 'everything';

    /** Discussions read at a time; their posts are read in batches of their own */
    private const int DISCUSSIONS_PER_BATCH = 200;

    private const int POSTS_PER_BATCH = 500;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public readonly string $scope,
        public readonly ?string $id = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 300];
    }

    public function handle(ActivityPublisherInterface $activity): void
    {
        if ($this->scope === self::DISCUSSION) {
            if ($this->id !== null) {
                $this->publishPosts($activity, [$this->id]);
            }

            return;
        }

        $discussions = DiscussionModel::query()->select('id');
        if ($this->scope === self::CATEGORY) {
            if ($this->id === null) {
                return;
            }

            $discussions->whereIn('category_id', [$this->id, ...CategoryModel::query()->where('parent_id', $this->id)->pluck('id')->all()]);
        }

        $discussions->chunkById(self::DISCUSSIONS_PER_BATCH, function (Collection $batch) use ($activity): void {
            /** @var list<string> $ids */
            $ids = $batch->modelKeys();
            foreach ($ids as $id) {
                $activity->recorded(DiscussionActivitySource::TYPE, $id);
            }

            $this->publishPosts($activity, $ids);
        });
    }

    /**
     * @param  list<string>  $discussionIds
     */
    private function publishPosts(ActivityPublisherInterface $activity, array $discussionIds): void
    {
        PostModel::query()->whereIn('discussion_id', $discussionIds)->select('id')->chunkById(self::POSTS_PER_BATCH, static function (Collection $posts) use ($activity): void {
            foreach ($posts->modelKeys() as $id) {
                $activity->recorded(PostActivitySource::TYPE, (string) $id);
            }
        });
    }
}
