<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Jobs;

use App\Application\Activity\Services\ActivityPublisherInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Modules\Forum\Infrastructure\Activity\DiscussionActivitySource;
use Modules\Forum\Infrastructure\Activity\PostActivitySource;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;

/**
 * Publishes again the activity of whatever depends on a change (forum#151); whether each subject
 * counts is decided when the consumer reads it, from the tables as they are then, and publishing
 * one that did not change is harmless.
 *
 * What may be big only lists ids and hands them to child jobs, one per batch, so no job grows
 * with the forum and a retry starts again from its own batch, never from the first discussion:
 *
 * - a category (its discussions and its subcategories' ones: who reads it, or where it hangs,
 *   changed) and the whole forum («Acceso al foro» changed) → batches of discussions;
 * - an author whose public name came or went → batches of their posts and discussions;
 * - a batch of discussions → each discussion and its posts; a batch of posts or of discussions
 *   alone → each one.
 */
final class PublishForumActivityJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string CATEGORY = 'category';

    public const string EVERYTHING = 'everything';

    public const string AUTHOR = 'author';

    /** A batch of discussions, each with its posts */
    public const string DISCUSSIONS = 'discussions';

    /** A batch of discussions, without their posts */
    public const string OPENED = 'opened';

    public const string POSTS = 'posts';

    public const int DISCUSSIONS_PER_BATCH = 200;

    public const int POSTS_PER_BATCH = 500;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @param  list<string>  $ids  the batch, for the batch scopes
     */
    public function __construct(
        public readonly string $scope,
        public readonly ?string $id = null,
        public readonly array $ids = [],
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 300];
    }

    public function handle(ActivityPublisherInterface $activity, Dispatcher $bus): void
    {
        switch ($this->scope) {
            case self::CATEGORY:
                if ($this->id !== null) {
                    $categoryIds = [$this->id, ...CategoryModel::query()->where('parent_id', $this->id)->pluck('id')->all()];
                    $this->inBatches($bus, DiscussionModel::query()->whereIn('category_id', $categoryIds), self::DISCUSSIONS, self::DISCUSSIONS_PER_BATCH);
                }
                break;
            case self::EVERYTHING:
                $this->inBatches($bus, DiscussionModel::query(), self::DISCUSSIONS, self::DISCUSSIONS_PER_BATCH);
                break;
            case self::AUTHOR:
                $this->author($bus);
                break;
            case self::DISCUSSIONS:
            case self::OPENED:
                $this->discussions($activity, withPosts: $this->scope === self::DISCUSSIONS);
                break;
            case self::POSTS:
                $this->publish($activity, PostActivitySource::TYPE, $this->ids);
                break;
        }
    }

    private function author(Dispatcher $bus): void
    {
        if ($this->id === null) {
            return;
        }

        // Only what spoke as a character depends on the public name, and every discussion they opened
        $this->inBatches($bus, PostModel::query()->where('author_id', $this->id)->where(static function (Builder $where): void {
            $where->whereNotNull('character_id')->orWhere('character_removed', true);
        }), self::POSTS, self::POSTS_PER_BATCH);
        $this->inBatches($bus, DiscussionModel::query()->where('author_id', $this->id), self::OPENED, self::DISCUSSIONS_PER_BATCH);
    }

    /**
     * @param  Builder<covariant DiscussionModel|PostModel>  $query
     */
    private function inBatches(Dispatcher $bus, Builder $query, string $scope, int $size): void
    {
        $query->select('id')->chunkById($size, static function (Collection $batch) use ($bus, $scope): void {
            $ids = array_values(array_map(strval(...), $batch->modelKeys()));
            $bus->dispatch(new self($scope, ids: $ids));
        });
    }

    private function discussions(ActivityPublisherInterface $activity, bool $withPosts): void
    {
        $this->publish($activity, DiscussionActivitySource::TYPE, $this->ids);

        if ($withPosts && $this->ids !== []) {
            PostModel::query()->whereIn('discussion_id', $this->ids)->select('id')->chunkById(self::POSTS_PER_BATCH, function (Collection $posts) use ($activity): void {
                $this->publish($activity, PostActivitySource::TYPE, array_values(array_map(strval(...), $posts->modelKeys())));
            });
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function publish(ActivityPublisherInterface $activity, string $type, array $ids): void
    {
        foreach ($ids as $id) {
            $activity->recorded($type, $id);
        }
    }
}
