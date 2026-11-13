<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\Conversation;
use Modules\Forum\Domain\Entities\ConversationMessage;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;

/**
 * Private conversations and their entries. Nothing here asks who may read them: whoever calls
 * has checked that the person is in the conversation.
 */
interface ConversationRepositoryInterface
{
    public function find(ConversationId $id): ?Conversation;

    /**
     * The conversation, locked until the transaction ends, to number its next entry.
     */
    public function lock(ConversationId $id): ?Conversation;

    public function save(Conversation $conversation): void;

    /**
     * Deletes the conversation with every entry and who was in it. Reports keep their copy.
     */
    public function delete(ConversationId $id): void;

    public function saveMessage(ConversationMessage $message): void;

    public function findMessage(ConversationMessageId $id): ?ConversationMessage;

    /**
     * Entries numbered from $from to $to (both included), in order.
     *
     * @return list<ConversationMessage>
     */
    public function entries(ConversationId $id, int $from, int $to): array;

    /**
     * Up to $before messages before the numbered entry, the entry, and up to $after messages
     * after it, in order. Notes of who joined or left in between come too.
     *
     * @return list<ConversationMessage>
     */
    public function around(ConversationId $id, int $number, int $before, int $after): array;
}
