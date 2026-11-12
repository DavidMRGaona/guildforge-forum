<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Listeners;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Modules\Forum\Domain\Events\ForumPostReported;
use Modules\Forum\Domain\Repositories\ReportRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\ReportId;
use Modules\Forum\Infrastructure\Services\CategoryModeratorFinder;
use Modules\Forum\Notifications\PostReportedNotification;
use Throwable;

/**
 * Tells the moderators of a post that someone reported it, except whoever reported it. Each
 * notice checks again, when delivered, that the person still moderates the post.
 */
final class NotifyModeratorsOfReport implements ShouldQueue
{
    public int $tries = 3;

    public function handle(ForumPostReported $event): void
    {
        $moderators = app(CategoryModeratorFinder::class);
        $reports = app(ReportRepositoryInterface::class);

        $reporterId = $reports->find(ReportId::fromString($event->reportId))?->reporterId();

        $recipients = $moderators->candidatesFor(CategoryId::fromString($event->categoryId))
            ->reject(static fn (UserModel $user): bool => $reporterId !== null && strtolower((string) $user->getKey()) === strtolower($reporterId))
            ->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new PostReportedNotification($event->reportId, $event->postId));
        }
    }

    public function failed(ForumPostReported $event, Throwable $exception): void
    {
        report($exception);
    }
}
