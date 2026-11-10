<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Modules\Forum\Application\Contracts\ForumCountersInterface;
use Modules\Forum\Application\DTOs\CategoryCountersDTO;
use Modules\Forum\Application\DTOs\DiscussionCountersDTO;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;

/**
 * Instants are copied as the database holds them (getRawOriginal), so no zone conversion can
 * shift them between the post and the counters. The rows are written through the base query,
 * so refreshing counters never touches updated_at.
 */
final class EloquentForumCounters implements ForumCountersInterface
{
    public function refreshDiscussion(DiscussionId $id): void
    {
        $discussion = DiscussionModel::query()->find($id->value, ['id', 'created_at']);
        if (! $discussion instanceof DiscussionModel) {
            return;
        }

        $visible = PostModel::query()
            ->where('discussion_id', $id->value)
            ->where('status', PostStatus::Visible->value);

        $last = (clone $visible)->orderByDesc('number')->first(['id', 'published_at']);

        DiscussionModel::query()->whereKey($id->value)->toBase()->update([
            'post_count' => (clone $visible)->count(),
            'last_post_id' => $last?->id,
            'last_activity_at' => $last instanceof PostModel ? $last->getRawOriginal('published_at') : $discussion->getRawOriginal('created_at'),
        ]);
    }

    public function refreshCategory(CategoryId $id): void
    {
        $counted = DiscussionModel::query()
            ->where('category_id', $id->value)
            ->where('is_hidden', false)
            ->where('post_count', '>', 0);

        $last = (clone $counted)->orderByDesc('last_activity_at')->orderByDesc('id')->first(['id', 'last_post_id', 'last_activity_at']);

        CategoryModel::query()->whereKey($id->value)->toBase()->update([
            'discussion_count' => (clone $counted)->count(),
            'post_count' => (int) (clone $counted)->sum('post_count'),
            'last_post_id' => $last?->last_post_id,
            'last_activity_at' => $last instanceof DiscussionModel ? $last->getRawOriginal('last_activity_at') : null,
        ]);
    }

    public function discussion(DiscussionId $id): ?DiscussionCountersDTO
    {
        $model = DiscussionModel::query()->find($id->value, ['id', 'post_count', 'last_post_id', 'last_activity_at', 'created_at']);
        if (! $model instanceof DiscussionModel) {
            return null;
        }

        return new DiscussionCountersDTO(
            postCount: $model->post_count,
            lastPostId: $model->last_post_id === null ? null : PostId::fromString($model->last_post_id),
            lastActivityAt: ($model->last_activity_at ?? $model->created_at)->toDateTimeImmutable(),
        );
    }

    public function category(CategoryId $id): ?CategoryCountersDTO
    {
        $model = CategoryModel::query()->find($id->value, ['id', 'discussion_count', 'post_count', 'last_post_id', 'last_activity_at']);
        if (! $model instanceof CategoryModel) {
            return null;
        }

        return new CategoryCountersDTO(
            discussionCount: $model->discussion_count,
            postCount: $model->post_count,
            lastPostId: $model->last_post_id === null ? null : PostId::fromString($model->last_post_id),
            lastActivityAt: $model->last_activity_at?->toDateTimeImmutable(),
        );
    }
}
