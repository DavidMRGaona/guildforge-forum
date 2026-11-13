<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Application\SiteNotifications\DTOs\SiteNotificationGroupDTO;
use Modules\Forum\Application\Contracts\ConversationParticipantsInterface;
use Modules\Forum\Domain\Entities\Conversation;
use Modules\Forum\Domain\Entities\ConversationMessage;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;

/**
 * A new message in a private conversation, or a conversation started with the person. On the
 * site, unread ones of one conversation gather in one notice. By mail, one per conversation until
 * the person reads it again.
 */
final class ConversationMessageNotification extends ConversationNotification
{
    private ConversationMessage|false|null $message = null;

    public function __construct(string $conversationId, string $actorId, public readonly string $messageId)
    {
        parent::__construct($conversationId, $actorId);
    }

    protected function stillHolds(Conversation $conversation, string $recipientId, string $channel): bool
    {
        $message = $this->message();
        if (! $message instanceof ConversationMessage || ! $message->isShown() || ! $message->conversationId()->equals($conversation->id())) {
            return false;
        }

        return $channel !== 'mail' || app(ConversationParticipantsInterface::class)->claimMail($conversation->id(), $recipientId, $message->number());
    }

    protected function title(Conversation $conversation, string $actor): string
    {
        $key = $this->message()?->number() === 1 ? 'started' : 'message';

        return __("forum::messages.conversations.notices.{$key}", ['author' => $actor, 'conversation' => $conversation->title()->value]);
    }

    protected function siteNotification(Conversation $conversation, string $actor): SiteNotificationDTO
    {
        $title = $conversation->title()->value;

        return new SiteNotificationDTO(
            title: $this->title($conversation, $actor),
            url: self::path($conversation),
            icon: 'mail',
            groupKey: 'forum.conversations:'.$conversation->id()->value,
            actorId: strtolower($this->actorId),
            groupedTitle: static fn (SiteNotificationGroupDTO $group): string => $group->otherActors === 0
                ? trans_choice('forum::messages.conversations.notices.grouped_same', $group->count, ['author' => $actor, 'count' => $group->count, 'conversation' => $title])
                : trans_choice('forum::messages.conversations.notices.grouped', $group->otherActors, ['author' => $actor, 'count' => $group->otherActors, 'conversation' => $title]),
        );
    }

    private function message(): ?ConversationMessage
    {
        $this->message ??= app(ConversationRepositoryInterface::class)->findMessage(ConversationMessageId::fromString($this->messageId)) ?? false;

        return $this->message instanceof ConversationMessage ? $this->message : null;
    }
}
