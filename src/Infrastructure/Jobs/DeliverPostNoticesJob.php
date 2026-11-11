<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Jobs;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use Modules\Forum\Application\Content\DocumentReferences;
use Modules\Forum\Application\Services\PostNoticePlanner;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostNoticeKind;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Notifications\DiscussionStartedNotification;
use Modules\Forum\Notifications\MentionedNotification;
use Modules\Forum\Notifications\PostNotification;
use Modules\Forum\Notifications\ReplyPostedNotification;
use Throwable;

/**
 * Works out who hears about a post once it is published (or edited) and queues one notice per
 * person. It reads the post as it is when the job runs: one hidden or deleted meanwhile tells
 * nobody. Each notice checks again, when delivered, that the person may read it.
 */
final class DeliverPostNoticesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * @param  PostBody|null  $before  what an edited post said before; null for a new post
     */
    public function __construct(
        public readonly string $postId,
        public readonly ?PostBody $before = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 300];
    }

    public function handle(PostRepositoryInterface $posts, DiscussionRepositoryInterface $discussions, PostNoticePlanner $planner): void
    {
        $post = $posts->find(PostId::fromString($this->postId));
        $discussion = $post instanceof Post ? $discussions->find($post->discussionId()) : null;
        if (! $post instanceof Post || ! $post->isVisible() || ! $discussion instanceof Discussion) {
            return;
        }

        $plan = $planner->plan($post, $discussion, $this->before instanceof PostBody ? DocumentReferences::of($this->before) : null);
        if ($plan === []) {
            return;
        }

        $recipients = UserModel::query()->whereKey(array_map(strval(...), array_keys($plan)))->get()
            ->keyBy(static fn (UserModel $user): string => strtolower((string) $user->getKey()));

        foreach (PostNoticeKind::cases() as $kind) {
            $users = $recipients->only(array_keys(array_filter($plan, static fn (PostNoticeKind $planned): bool => $planned === $kind)));
            if ($users->isNotEmpty()) {
                Notification::send($users->values(), $this->notification($kind, $discussion));
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }

    private function notification(PostNoticeKind $kind, Discussion $discussion): PostNotification
    {
        return match ($kind) {
            PostNoticeKind::Mention, PostNoticeKind::Quote => new MentionedNotification($this->postId, $kind),
            PostNoticeKind::Reply => new ReplyPostedNotification($this->postId),
            PostNoticeKind::NewDiscussion => new DiscussionStartedNotification($this->postId, $discussion->categoryId()->value),
        };
    }
}
