<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Forum\Application\Contracts\ForumNoticesInterface;
use Modules\Forum\Application\Services\ForumMailSubjects;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Jobs\DeliverPostNoticesJob;
use Modules\Forum\Notifications\PostLikedNotification;
use Modules\Forum\Notifications\SpaceOpenedNotification;
use Throwable;

/**
 * The forum's notices through the queue, each queued once the write that caused it commits.
 */
final readonly class QueuedForumNotices implements ForumNoticesInterface
{
    public function __construct(
        private PostRepositoryInterface $posts,
        private MailSubjectMuteServiceInterface $mutes,
    ) {}

    public function postPublished(PostId $postId): void
    {
        DeliverPostNoticesJob::dispatch($postId->value)->afterCommit();
    }

    public function postEdited(PostId $postId, PostBody $before): void
    {
        DeliverPostNoticesJob::dispatch($postId->value, $before)->afterCommit();
    }

    public function postLiked(PostId $postId, string $likerId): void
    {
        $post = $this->posts->find($postId);
        $authorId = $post instanceof Post ? $post->authorId() : null;
        $author = $authorId === null ? null : UserModel::query()->find($authorId);

        $author?->notify(new PostLikedNotification($postId->value, strtolower($likerId)));
    }

    public function addedToSpace(CategoryId $spaceId, string $accountId): void
    {
        UserModel::query()->find($accountId)?->notify(new SpaceOpenedNotification($spaceId->value));
    }

    public function forgetDiscussion(DiscussionId $discussionId): void
    {
        $this->forget(ForumMailSubjects::DISCUSSION, $discussionId->value);
    }

    public function forgetCategory(CategoryId $categoryId): void
    {
        $this->forget(ForumMailSubjects::CATEGORY, $categoryId->value);
    }

    /**
     * Once the deletion commits. The thing is gone either way: a failure only leaves its mutes
     * and notices behind, and is logged.
     */
    private function forget(string $type, string $id): void
    {
        DB::afterCommit(function () use ($type, $id): void {
            try {
                $this->mutes->forgetSubject($type, $id);
            } catch (Throwable $exception) {
                Log::warning('The forum could not forget the mutes and notices of a deleted thing.', ['type' => $type, 'id' => $id, 'exception' => $exception->getMessage()]);
            }
        });
    }
}
