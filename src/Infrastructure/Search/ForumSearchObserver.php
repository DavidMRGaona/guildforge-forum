<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Search;

use Illuminate\Support\Facades\Log;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Throwable;

/**
 * Keeps the site search in line with the forum's writes (model events of posts, discussions and
 * categories). A search that cannot be told is logged and never makes a forum write fail: the daily
 * reconciliation of the core brings the index back in line.
 */
final class ForumSearchObserver
{
    public static function register(): void
    {
        $observer = new self;

        PostModel::saved(static fn (PostModel $post) => $observer->guard(static function (ForumSearchRefresher $search) use ($post): void {
            $search->post($post->id);
            // The opening message is part of its discussion's document
            $search->discussion($post->discussion_id);
        }));
        PostModel::deleted(static fn (PostModel $post) => $observer->guard(static function (ForumSearchRefresher $search) use ($post): void {
            $search->forgetPost($post->id);
            $search->discussion($post->discussion_id);
        }));

        DiscussionModel::saved(static fn (DiscussionModel $discussion) => $observer->guard(static function (ForumSearchRefresher $search) use ($discussion): void {
            $search->discussion($discussion->id);
            if (! $discussion->wasRecentlyCreated && $discussion->wasChanged(['title', 'number', 'category_id', 'is_hidden', 'awaiting_approval'])) {
                $search->repliesOf($discussion->id);
            }
        }));
        // Before the row goes: its replies go with it (cascade), so their ids are read first
        DiscussionModel::deleting(static fn (DiscussionModel $discussion) => $observer->guard(static function (ForumSearchRefresher $search) use ($discussion): void {
            $search->forgetDiscussion($discussion->id);
            foreach (PostModel::query()->where('discussion_id', $discussion->id)->pluck('id') as $postId) {
                $search->forgetPost((string) $postId);
            }
        }));

        CategoryModel::saved(static fn (CategoryModel $category) => $observer->guard(static function (ForumSearchRefresher $search) use ($category): void {
            if (! $category->wasRecentlyCreated && $category->wasChanged(['name', 'view_rule', 'parent_id'])) {
                $search->category($category->id);
            }
        }));
    }

    /**
     * @param  callable(ForumSearchRefresher): void  $tell
     */
    public function guard(callable $tell): void
    {
        try {
            $tell(resolve(ForumSearchRefresher::class));
        } catch (Throwable $exception) {
            Log::warning('[Forum] The site search could not be told about a change.', ['exception' => $exception]);
        }
    }
}
