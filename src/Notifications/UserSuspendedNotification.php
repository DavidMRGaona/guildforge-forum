<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Services\TimezoneServiceInterface;
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
use Modules\Forum\Domain\Entities\Suspension;
use Modules\Forum\Domain\Repositories\SuspensionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\SuspensionId;

/**
 * «Se te ha suspendido en el foro», with the reason and until when (or until it is lifted), on
 * the site and by mail. It is checked again when delivered: it only tells what is still true, so
 * a suspension lifted, expired or replaced by a newer one meanwhile tells nothing, nor does
 * an account that cannot be reached any more.
 */
final class UserSuspendedNotification extends Notification implements HasMailCategory, HasSiteNotification, ShouldQueue
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
        return $this->inForceFor($notifiable) instanceof Suspension;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $suspension = $this->inForceFor($notifiable);
        if (! $suspension instanceof Suspension) {
            // shouldSend() said no already; a mail with nothing in it is never built
            return new MailMessage;
        }

        return (new MailMessage)
            ->subject($this->title($suspension))
            ->greeting(__('forum::messages.notices.mail.greeting'))
            ->line(EscapesMarkdown::escape($this->title($suspension)).'.')
            ->line(EscapesMarkdown::escape($this->reason($suspension)))
            ->action(__('forum::messages.notices.suspended.action'), app(ForumUrlsInterface::class)->index(absolute: true));
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        $suspension = $this->inForceFor($notifiable);
        if (! $suspension instanceof Suspension) {
            return null;
        }

        return new SiteNotificationDTO(
            title: $this->title($suspension),
            url: app(ForumUrlsInterface::class)->index(),
            body: $this->reason($suspension),
            icon: 'ban',
        );
    }

    private function title(Suspension $suspension): string
    {
        $endsAt = $suspension->endsAt();

        return $endsAt instanceof \DateTimeImmutable
            ? __('forum::messages.notices.suspended.until', ['date' => app(TimezoneServiceInterface::class)->format($endsAt)])
            : __('forum::messages.notices.suspended.until_lifted');
    }

    private function reason(Suspension $suspension): string
    {
        return __('forum::messages.notices.suspended.reason', ['reason' => $suspension->reason()]);
    }

    /**
     * The suspension, when it is still the one in force for this reachable account.
     */
    private function inForceFor(object $notifiable): ?Suspension
    {
        $accountId = $notifiable instanceof Authenticatable ? ForumBrowser::accountId($notifiable) : null;
        if ($accountId === null || app(AccountDirectoryInterface::class)->mentionable([$accountId]) === []) {
            return null;
        }

        $current = app(SuspensionRepositoryInterface::class)->activeFor($accountId, CarbonImmutable::now());

        return $current instanceof Suspension && $current->id()->equals(SuspensionId::fromString($this->suspensionId)) ? $current : null;
    }
}
