<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Jobs;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\ForumLiveSignalsInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Tells whoever follows a post's discussion, or its category (or the category above it), that
 * the forum's mark in their menu may have changed, if they may still read it. The message carries nothing: each menu asks for
 * its count, which only counts what the person may read. Never its author. Tried once, like every
 * real-time message: a menu that misses it catches up when it reconnects or reloads.
 */
final class SignalFollowersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Followers read from the database at a time */
    public const int BATCH = 200;

    public int $tries = 1;

    public function __construct(
        public readonly string $postId,
    ) {}

    public function handle(
        PostRepositoryInterface $posts,
        DiscussionRepositoryInterface $discussions,
        CategoryRepositoryInterface $categories,
        FollowsInterface $follows,
        ForumAccessInterface $access,
        ForumLiveSignalsInterface $signals,
    ): void {
        $post = $posts->find(PostId::fromString($this->postId));
        $discussion = $post instanceof Post ? $discussions->find($post->discussionId()) : null;
        if (! $post instanceof Post || ! $post->isVisible() || ! $discussion instanceof Discussion) {
            return;
        }

        $categoryIds = [$discussion->categoryId()];
        $parentId = $categories->find($discussion->categoryId())?->parentId();
        if ($parentId instanceof CategoryId) {
            $categoryIds[] = $parentId;
        }

        $authorId = $post->authorId();
        $accountIds = array_values(array_filter(
            array_unique(array_map(strtolower(...), [...$follows->discussionFollowers($discussion->id()), ...$follows->categoryFollowers($categoryIds)])),
            static fn (string $accountId): bool => $accountId !== $authorId,
        ));

        // Only whoever may read the discussion now: a follow outlives the access it was made with
        foreach (array_chunk($accountIds, self::BATCH) as $batch) {
            foreach (UserModel::query()->whereKey($batch)->get() as $follower) {
                if ($access->canRead($follower, $discussion)) {
                    $signals->pulseChanged(strtolower((string) $follower->getKey()));
                }
            }
        }
    }
}
