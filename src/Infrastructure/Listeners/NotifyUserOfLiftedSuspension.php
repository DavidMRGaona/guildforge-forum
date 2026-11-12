<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Listeners;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Forum\Domain\Events\ForumUserSuspensionLifted;
use Modules\Forum\Notifications\SuspensionLiftedNotification;
use Throwable;

/**
 * Tells the person that moderation lifted their suspension.
 */
final class NotifyUserOfLiftedSuspension implements ShouldQueue
{
    public int $tries = 3;

    public function handle(ForumUserSuspensionLifted $event): void
    {
        UserModel::query()->find($event->userId)?->notify(new SuspensionLiftedNotification($event->suspensionId));
    }

    public function failed(ForumUserSuspensionLifted $event, Throwable $exception): void
    {
        report($exception);
    }
}
