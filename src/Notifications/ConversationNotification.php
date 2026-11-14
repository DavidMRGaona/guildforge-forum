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
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ConversationParticipantsInterface;
use Modules\Forum\Application\Services\ForumMailSubjects;
use Modules\Forum\Application\Services\ForumVisibility;
use Modules\Forum\Domain\Entities\Conversation;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Throwable;

/**
 * A notice about a private conversation, on the site and by mail. It never carries what anyone
 * wrote: only who and the conversation's title, so a mail or the bell shows nothing a person
 * outside the conversation should not read. Everything is read when it is delivered, and it is
 * not delivered when the person is no longer in the conversation (it fails closed).
 */
abstract class ConversationNotification extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification, ShouldQueue
{
    use Queueable;

    private Conversation|false|null $conversation = null;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $actorId,
    ) {
        $this->afterCommit();
    }

    abstract protected function title(Conversation $conversation, string $actor): string;

    /**
     * Whether the notice still holds once the person is in the conversation.
     */
    protected function stillHolds(Conversation $conversation, string $recipientId, string $channel): bool
    {
        return true;
    }

    public function mailCategory(): string
    {
        return ForumMailCategories::CONVERSATIONS;
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

        $conversation = $this->conversation();
        $recipientId = $this->idOf($notifiable);
        if (! $conversation instanceof Conversation || $recipientId === null || $recipientId === strtolower($this->actorId)) {
            return false;
        }

        try {
            return app(ConversationParticipantsInterface::class)->isIn($conversation->id(), $recipientId)
                && $this->stillHolds($conversation, $recipientId, $channel);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function mailSubject(): ?MailSubjectDTO
    {
        $conversation = $this->conversation();

        return $conversation instanceof Conversation ? ForumMailSubjects::conversation($conversation) : null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $conversation = $this->conversation();
        if (! $conversation instanceof Conversation) {
            return new MailMessage;
        }

        $title = $this->title($conversation, $this->actorName());

        return (new MailMessage)
            ->subject($title)
            ->greeting(__('forum::messages.notices.mail.greeting'))
            ->line(EscapesMarkdown::escape($title).'.')
            ->line(__('forum::messages.conversations.notices.mail_privacy'))
            ->action(__('forum::messages.conversations.notices.mail_action'), route('forum.conversations.show', ['conversation' => $conversation->id()->value]));
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        $conversation = $this->conversation();

        return $conversation instanceof Conversation ? $this->siteNotification($conversation, $this->actorName()) : null;
    }

    protected function siteNotification(Conversation $conversation, string $actor): SiteNotificationDTO
    {
        return new SiteNotificationDTO(
            title: $this->title($conversation, $actor),
            url: self::path($conversation),
            icon: 'mail',
            actorId: strtolower($this->actorId),
        );
    }

    protected static function path(Conversation $conversation): string
    {
        return '/'.ltrim((string) parse_url(route('forum.conversations.show', ['conversation' => $conversation->id()->value]), PHP_URL_PATH), '/');
    }

    protected function conversation(): ?Conversation
    {
        if ($this->conversation === null) {
            try {
                $this->conversation = app(ConversationRepositoryInterface::class)->find(ConversationId::fromString($this->conversationId)) ?? false;
            } catch (Throwable $exception) {
                report($exception);
                $this->conversation = false;
            }
        }

        return $this->conversation instanceof Conversation ? $this->conversation : null;
    }

    private function actorName(): string
    {
        return app(AccountDirectoryInterface::class)->namesOf([$this->actorId])[strtolower($this->actorId)] ?? __('forum::messages.notices.someone');
    }

    private function idOf(object $notifiable): ?string
    {
        $key = method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null;

        return is_scalar($key) && (string) $key !== '' ? strtolower((string) $key) : null;
    }
}
