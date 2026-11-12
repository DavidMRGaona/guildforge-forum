<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Jobs;

use App\Application\Search\Services\SearchIndexerInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Infrastructure\Search\DiscussionSearchSource;
use Modules\Forum\Infrastructure\Search\PostSearchSource;

/**
 * Asks the site search to refresh every document that depends on one discussion (its replies carry its
 * title and category) or on one category (its discussions, its subcategories' ones and their replies).
 * What each document becomes is decided when the refresh runs, from the tables as they are then.
 */
final class RefreshForumSearchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string DISCUSSION = 'discussion';

    public const string CATEGORY = 'category';

    public int $tries = 3;

    public function __construct(
        public readonly string $scope,
        public readonly string $id,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 300];
    }

    public function handle(SearchIndexerInterface $indexer): void
    {
        if ($this->scope === self::DISCUSSION) {
            $this->refreshReplies($indexer, [$this->id]);

            return;
        }

        $categoryIds = [$this->id, ...CategoryModel::query()->where('parent_id', $this->id)->pluck('id')->all()];

        DiscussionModel::query()->whereIn('category_id', $categoryIds)->select('id')->chunkById(200, function ($discussions) use ($indexer): void {
            /** @var list<string> $ids */
            $ids = $discussions->modelKeys();
            foreach ($ids as $id) {
                $indexer->refresh(DiscussionSearchSource::TYPE, $id);
            }

            $this->refreshReplies($indexer, $ids);
        });
    }

    /**
     * @param  list<string>  $discussionIds
     */
    private function refreshReplies(SearchIndexerInterface $indexer, array $discussionIds): void
    {
        PostModel::query()->whereIn('discussion_id', $discussionIds)->select('id')->chunkById(500, static function ($posts) use ($indexer): void {
            foreach ($posts->modelKeys() as $id) {
                $indexer->refresh(PostSearchSource::TYPE, (string) $id);
            }
        });
    }
}
