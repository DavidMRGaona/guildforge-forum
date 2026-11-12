<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Listeners;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Forum\Domain\Events\ForumUserSuspended;
use Modules\Forum\Notifications\UserSuspendedNotification;
use Throwable;

/**
 * Tells the person that moderation suspended them, with the reason and until when.
 */
final class NotifyUserOfSuspension implements ShouldQueue
{
    public int $tries = 3;

    public function handle(ForumUserSuspended $event): void
    {
        UserModel::query()->find($event->userId)?->notify(new UserSuspendedNotification($event->suspensionId));
    }

    public function failed(ForumUserSuspended $event, Throwable $exception): void
    {
        report($exception);
    }
}
