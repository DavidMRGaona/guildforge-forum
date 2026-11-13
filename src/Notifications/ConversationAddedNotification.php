<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use Modules\Forum\Domain\Entities\Conversation;

/**
 * Someone added the person to a private conversation.
 */
final class ConversationAddedNotification extends ConversationNotification
{
    protected function title(Conversation $conversation, string $actor): string
    {
        return __('forum::messages.conversations.notices.added', ['author' => $actor, 'conversation' => $conversation->title()->value]);
    }
}
