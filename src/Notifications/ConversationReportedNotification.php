<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Domain\Entities\ConversationReport;
use Modules\Forum\Domain\Repositories\ConversationReportRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Domain\ValueObjects\ConversationReportId;
use Throwable;

/**
 * «Han denunciado un mensaje privado»: for whoever moderates the whole forum. It says nothing
 * about who reported, who wrote it nor what it says; the moderation page shows the copy. One per
 * report while it is open, gathered on the site, and by mail only for the first open report of
 * the message.
 */
final class ConversationReportedNotification extends Notification implements HasMailCategory, HasSiteNotification, ShouldQueue
{
    use Queueable;

    private ConversationReport|false|null $report = null;

    public function __construct(
        public readonly string $reportId,
    ) {
        $this->afterCommit();
    }

    public function mailCategory(): string
    {
        return ForumMailCategories::REPORTS;
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
        $report = $this->report();
        if (! $report instanceof ConversationReport || ! $report->isOpen() || ! $notifiable instanceof Authenticatable) {
            return false;
        }
        if ($report->reporterId() !== null && strtolower((string) $notifiable->getAuthIdentifier()) === $report->reporterId()) {
            return false;
        }
        if (! app(CategoryAccessPolicy::class)->canModerateForum($notifiable)) {
            return false;
        }

        $messageId = $report->messageId();
        if ($channel !== 'mail' || ! $messageId instanceof ConversationMessageId) {
            return true;
        }

        $oldest = app(ConversationReportRepositoryInterface::class)->openOf($messageId)[0] ?? null;

        return $oldest === null || $oldest->id()->equals($report->id());
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('forum::messages.conversations.notices.reported'))
            ->greeting(__('forum::messages.notices.mail.greeting'))
            ->line(__('forum::messages.conversations.notices.reported').'.')
            ->action(__('forum::messages.moderation.queue_title'), route('forum.moderation.index'));
    }

    public function toSiteNotification(object $notifiable): SiteNotificationDTO
    {
        return new SiteNotificationDTO(
            title: __('forum::messages.conversations.notices.reported'),
            url: '/'.ltrim((string) parse_url(route('forum.moderation.index'), PHP_URL_PATH), '/'),
            icon: 'flag',
            groupKey: 'forum.conversation-reports',
        );
    }

    private function report(): ?ConversationReport
    {
        if ($this->report === null) {
            try {
                $this->report = app(ConversationReportRepositoryInterface::class)->find(ConversationReportId::fromString($this->reportId)) ?? false;
            } catch (Throwable $exception) {
                report($exception);
                $this->report = false;
            }
        }

        return $this->report instanceof ConversationReport ? $this->report : null;
    }
}
