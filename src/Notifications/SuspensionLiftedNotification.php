<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Notifications\Support\EscapesMarkdown;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Application\Services\ForumVisibility;
use Modules\Forum\Domain\Entities\Suspension;
use Modules\Forum\Domain\Repositories\SuspensionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\SuspensionId;

/**
 * «Se ha levantado tu suspensión en el foro», short, on the site and by mail. It is checked
 * again when delivered: it only tells what is still true, so it says nothing to an account that
 * cannot be reached any more (gone, deactivated, anonymized, the system account) nor to someone
 * suspended again meanwhile. It never repeats the reason or says who lifted it.
 */
final class SuspensionLiftedNotification extends Notification implements HasMailCategory, HasSiteNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $suspensionId,
    ) {
        $this->afterCommit();
    }

    public function mailCategory(): string
    {
        return ForumMailCategories::SUSPENSIONS;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', HasSiteNotification::CHANNEL];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        // Whoever may not see a forum in preview hears nothing from it
        if (! app(ForumVisibility::class)->isVisibleTo($notifiable)) {
            return false;
        }

        $accountId = $notifiable instanceof Authenticatable ? ForumBrowser::accountId($notifiable) : null;
        if ($accountId === null || app(AccountDirectoryInterface::class)->mentionable([$accountId]) === []) {
            return false;
        }

        $repository = app(SuspensionRepositoryInterface::class);
        $lifted = $repository->find(SuspensionId::fromString($this->suspensionId));
        if (! $lifted instanceof Suspension || $lifted->userId() !== $accountId || ! $lifted->liftedAt() instanceof \DateTimeImmutable) {
            return false;
        }

        // Another suspension in force means they still cannot write: nothing to celebrate
        return ! $repository->activeFor($accountId, CarbonImmutable::now()) instanceof Suspension;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting(__('forum::messages.notices.mail.greeting'))
            ->line(EscapesMarkdown::escape($this->title()).'.')
            ->action(__('forum::messages.notices.suspension_lifted.action'), app(ForumUrlsInterface::class)->index(absolute: true));
    }

    public function toSiteNotification(object $notifiable): SiteNotificationDTO
    {
        return new SiteNotificationDTO(
            title: $this->title(),
            url: app(ForumUrlsInterface::class)->index(),
            icon: 'circle-check',
        );
    }

    private function title(): string
    {
        return __('forum::messages.notices.suspension_lifted.title');
    }
}
