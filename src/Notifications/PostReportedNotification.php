<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Application\SiteNotifications\DTOs\SiteNotificationGroupDTO;
use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Entities\Report;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\ReportRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\ReportId;

/**
 * «Han denunciado un mensaje»: for whoever moderates the post. The reports still open of one post
 * gather in one notice on the site, and only the first one of them goes by mail. It says nothing
 * about who reported nor what they wrote. It is delivered only while the report is open and the
 * person still moderates the post (checked when delivered).
 */
final class PostReportedNotification extends Notification implements HasMailCategory, HasSiteNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $reportId,
        public readonly string $postId,
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
        $context = $this->contextFor($notifiable);
        if ($context === null) {
            return false;
        }

        // A mail for each report would flood moderators: the oldest open report of the post sends it
        return $channel !== 'mail' || $context['oldestOpenId'] === $this->reportId;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $context = $this->contextFor($notifiable);
        if ($context === null) {
            // shouldSend() said no already; a mail with nothing in it is never built
            return new MailMessage;
        }

        return (new MailMessage)
            ->subject($this->title($context['discussion']))
            ->greeting(__('forum::messages.notices.mail.greeting'))
            ->line(EscapesMarkdown::escape($this->title($context['discussion'])).'.')
            ->line(EscapesMarkdown::escape($this->reason($context['report'])))
            ->action(__('forum::messages.notices.reported.action'), $context['url']);
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        $context = $this->contextFor($notifiable);
        if ($context === null) {
            return null;
        }

        $discussion = $context['discussion'];
        $title = $discussion->title()->value;

        return new SiteNotificationDTO(
            title: $this->title($discussion),
            url: $context['path'],
            body: $this->reason($context['report']),
            icon: 'flag',
            groupKey: 'forum.reports:'.$this->postId,
            groupedTitle: static fn (SiteNotificationGroupDTO $group): string => __('forum::messages.notices.reported.grouped', ['count' => max(2, $group->count), 'discussion' => $title]),
        );
    }

    private function title(Discussion $discussion): string
    {
        return __('forum::messages.notices.reported.title', ['discussion' => $discussion->title()->value]);
    }

    private function reason(Report $report): string
    {
        return __('forum::messages.notices.reported.reason', ['reason' => __("forum::messages.reports.reasons.{$report->reason()->value}")]);
    }

    /**
     * What the notice shows, or null when it must not reach the person: the account cannot be
     * reached, the report is settled, the post or its discussion is gone, or the person does not
     * moderate it any more.
     *
     * @return array{report: Report, discussion: Discussion, oldestOpenId: string, path: string, url: string}|null
     */
    private function contextFor(object $notifiable): ?array
    {
        $accountId = $notifiable instanceof Authenticatable ? ForumBrowser::accountId($notifiable) : null;
        if (! $notifiable instanceof Authenticatable || $accountId === null || app(AccountDirectoryInterface::class)->mentionable([$accountId]) === []) {
            return null;
        }

        $postId = PostId::fromString($this->postId);
        $open = app(ReportRepositoryInterface::class)->openOf($postId);
        $report = null;
        foreach ($open as $candidate) {
            if ($candidate->id()->equals(ReportId::fromString($this->reportId))) {
                $report = $candidate;
            }
        }

        $post = app(PostRepositoryInterface::class)->find($postId);
        $discussion = $post instanceof Post ? app(DiscussionRepositoryInterface::class)->find($post->discussionId()) : null;
        if (! $report instanceof Report || ! $discussion instanceof Discussion || ! app(ForumAccessInterface::class)->canModerate($notifiable, $discussion)) {
            return null;
        }

        $urls = app(ForumUrlsInterface::class);
        $number = $post->number()?->value;

        return [
            'report' => $report,
            'discussion' => $discussion,
            'oldestOpenId' => $open[0]->id()->value,
            'path' => $urls->discussion($discussion, $number),
            'url' => $urls->discussion($discussion, $number, absolute: true),
        ];
    }
}
