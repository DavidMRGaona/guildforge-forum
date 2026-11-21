<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Activity;

use Illuminate\Support\Facades\Log;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CharacterModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Throwable;

/**
 * Keeps the activity contract in line with the forum's writes (forum#151), from the model events
 * of posts, discussions and categories, like the site search: every write goes through them
 * (ForumWriter, moderation, moving and deleting categories), so publishing, approving, hiding,
 * showing, deleting, moving, splitting and merging are all told without each path remembering it.
 * Only the changes that may decide whether something counts are told; a failure is logged and
 * never makes a forum write fail.
 */
final class ForumActivityObserver
{
    /** What decides whether a post counts, and for whom */
    private const array POST_COLUMNS = ['status', 'number', 'published_at', 'discussion_id', 'author_id', 'character_id', 'character_removed'];

    /** What decides whether a discussion counts; the first four also decide whether its posts do */
    private const array DISCUSSION_COLUMNS = ['category_id', 'is_hidden', 'awaiting_approval', 'number', 'author_id'];

    private const array DISCUSSION_COLUMNS_FOR_POSTS = ['category_id', 'is_hidden', 'awaiting_approval', 'number'];

    /** Who reads a category: its own rule, its parent's and what it depends on */
    private const array CATEGORY_COLUMNS = ['view_rule', 'parent_id', 'subject_type', 'subject_id'];

    public static function register(): void
    {
        $observer = new self;

        PostModel::saved(static fn (PostModel $post) => $observer->guard(static function (ForumActivityChanges $activity) use ($post): void {
            if (! $post->wasRecentlyCreated && ! $post->wasChanged(self::POST_COLUMNS)) {
                return;
            }

            $activity->post($post->id);
            // Whether a discussion counts depends on it holding a visible post
            $activity->discussion($post->discussion_id);
            // Split or merged away: the one it left may hold no visible post now
            $previous = $post->getPrevious()['discussion_id'] ?? null;
            if ($post->wasChanged('discussion_id') && is_string($previous)) {
                $activity->discussion($previous);
            }
        }));
        PostModel::deleted(static fn (PostModel $post) => $observer->guard(static function (ForumActivityChanges $activity) use ($post): void {
            $activity->post($post->id);
            $activity->discussion($post->discussion_id);
        }));

        DiscussionModel::saved(static fn (DiscussionModel $discussion) => $observer->guard(static function (ForumActivityChanges $activity) use ($discussion): void {
            if ($discussion->wasRecentlyCreated || $discussion->wasChanged(self::DISCUSSION_COLUMNS)) {
                $activity->discussion($discussion->id);
            }

            if (! $discussion->wasRecentlyCreated && $discussion->wasChanged(self::DISCUSSION_COLUMNS_FOR_POSTS)) {
                $activity->postsOf($discussion->id);
            }
        }));
        // Before the row goes: its posts go with it (cascade), so their ids are read first
        DiscussionModel::deleting(static fn (DiscussionModel $discussion) => $observer->guard(static function (ForumActivityChanges $activity) use ($discussion): void {
            $activity->discussion($discussion->id);
            foreach (PostModel::query()->where('discussion_id', $discussion->id)->pluck('id') as $postId) {
                $activity->post((string) $postId);
            }
        }));

        // A shared character hides who wrote as it; once it is gone its posts name their writer and count
        CharacterModel::deleting(static fn (CharacterModel $character) => $observer->guard(static function (ForumActivityChanges $activity) use ($character): void {
            if ($character->owner_id !== null) {
                return;
            }

            foreach (PostModel::query()->where('character_id', $character->id)->pluck('id') as $postId) {
                $activity->post((string) $postId);
            }
        }));

        CategoryModel::saved(static fn (CategoryModel $category) => $observer->guard(static function (ForumActivityChanges $activity) use ($category): void {
            if (! $category->wasRecentlyCreated && $category->wasChanged(self::CATEGORY_COLUMNS)) {
                $activity->category($category->id);
            }
        }));
    }

    /**
     * @param  callable(ForumActivityChanges): void  $tell
     */
    public function guard(callable $tell): void
    {
        try {
            $tell(resolve(ForumActivityChanges::class));
        } catch (Throwable $exception) {
            Log::warning('[Forum] The activity contract could not be told about a change.', ['exception' => $exception::class]);
        }
    }
}
