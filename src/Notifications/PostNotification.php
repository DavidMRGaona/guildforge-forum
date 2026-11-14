<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Mail\Contracts\HasMailSubject;
use App\Application\Mail\DTOs\MailSubjectDTO;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Forum\Application\DTOs\PostNoticeDTO;
use Modules\Forum\Application\Services\ForumMailSubjects;
use Modules\Forum\Application\Services\ForumVisibility;
use Modules\Forum\Application\Services\PostNoticeContext;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * A notice about one post, on the site and by mail. Everything it shows is read when it is
 * delivered, and it is not delivered at all when the person may no longer read the post
 * (shouldSend(), for each channel). It is about the post's discussion, so muting the discussion
 * holds it back.
 */
abstract class PostNotification extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification, ShouldQueue
{
    use Queueable;

    /** @var array<string, PostNoticeDTO|false> by recipient id, read once per delivery */
    private array $contexts = [];

    public function __construct(
        public readonly string $postId,
    ) {
        $this->afterCommit();
    }

    /**
     * The notice's title, also the mail's subject.
     */
    abstract protected function title(PostNoticeDTO $notice): string;

    /**
     * Whether the notice still holds for the person once they may read the post (they still
     * follow it, for instance), on the channel about to deliver it.
     */
    protected function stillHolds(PostNoticeDTO $notice, string $channel): bool
    {
        return true;
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

        $notice = $this->notice($notifiable);

        return $notice instanceof PostNoticeDTO && $this->stillHolds($notice, $channel);
    }

    public function mailSubject(): ?MailSubjectDTO
    {
        $discussion = app(PostNoticeContext::class)->discussionOfPost(PostId::fromString($this->postId));

        return $discussion instanceof Discussion ? ForumMailSubjects::discussion($discussion) : null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $notice = $this->notice($notifiable);
        if (! $notice instanceof PostNoticeDTO) {
            // shouldSend() said no already; a mail with nothing in it is never built
            return new MailMessage;
        }

        $mail = (new MailMessage)
            ->subject($this->title($notice))
            ->greeting(__('forum::messages.notices.mail.greeting'))
            ->line(EscapesMarkdown::escape($this->title($notice)).'.');

        if ($notice->excerpt !== '') {
            $mail->line('«'.EscapesMarkdown::escape($notice->excerpt).'»');
        }

        return $mail->action(__('forum::messages.notices.mail.action'), $notice->url);
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        $notice = $this->notice($notifiable);

        return $notice instanceof PostNoticeDTO ? $this->siteNotification($notice) : null;
    }

    protected function siteNotification(PostNoticeDTO $notice): SiteNotificationDTO
    {
        return new SiteNotificationDTO(
            title: $this->title($notice),
            url: $notice->path,
            body: $notice->excerpt === '' ? null : $notice->excerpt,
            icon: $this->icon(),
            actorId: $notice->actorId(),
        );
    }

    protected function icon(): string
    {
        return 'message-square';
    }

    /**
     * The name to show for the post's author: their name, or «Alguien» for an account that is gone.
     */
    protected static function authorOf(PostNoticeDTO $notice): string
    {
        return $notice->authorName ?? __('forum::messages.notices.someone');
    }

    protected function notice(object $notifiable): ?PostNoticeDTO
    {
        $key = spl_object_id($notifiable).':'.(method_exists($notifiable, 'getKey') ? (string) $notifiable->getKey() : '');
        if (! array_key_exists($key, $this->contexts)) {
            $this->contexts[$key] = app(PostNoticeContext::class)->for($notifiable, PostId::fromString($this->postId)) ?? false;
        }

        $notice = $this->contexts[$key];

        return $notice instanceof PostNoticeDTO ? $notice : null;
    }
}
