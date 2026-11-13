<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Domain\ValueObjects\ConversationReportId;

/**
 * What private conversations tell people, after the commit. Whoever receives a notice is
 * checked again when it is delivered: still in the conversation, the message still there.
 */
interface ConversationNoticesInterface
{
    /**
     * A message was written: everyone else in the conversation hears of it.
     */
    public function messageWritten(ConversationId $conversationId, ConversationMessageId $messageId): void;

    /**
     * The person was added to the conversation (or it was started with them).
     */
    public function added(ConversationId $conversationId, string $addedId, string $adderId): void;

    /**
     * A message was reported: whoever moderates the whole forum hears of it.
     */
    public function reported(ConversationReportId $reportId): void;

    /**
     * The conversation is gone: its notices go too.
     */
    public function forgetConversation(ConversationId $conversationId): void;
}
