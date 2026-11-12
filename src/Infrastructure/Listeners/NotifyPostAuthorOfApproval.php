<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Listeners;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Forum\Domain\Events\ForumPostApproved;
use Modules\Forum\Notifications\PostApprovedNotification;
use Throwable;

/**
 * Tells the author of a post that moderation approved it. The only path of this notice: what
 * the followers hear comes from ForumWriter::approvePost() and never reaches the author, so
 * nobody is told twice.
 */
final class NotifyPostAuthorOfApproval implements ShouldQueue
{
    public int $tries = 3;

    public function handle(ForumPostApproved $event): void
    {
        $author = $event->authorId === null ? null : UserModel::query()->find($event->authorId);

        $author?->notify(new PostApprovedNotification($event->postId, $event->openedDiscussion));
    }

    public function failed(ForumPostApproved $event, Throwable $exception): void
    {
        report($exception);
    }
}
