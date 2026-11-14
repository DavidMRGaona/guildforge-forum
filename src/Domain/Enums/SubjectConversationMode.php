<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * How a record's page (an event, a game table, a campaign) shows the conversation of its
 * discussion: one per publication type, the forum setting `subject_conversations`.
 */
enum SubjectConversationMode: string
{
    /** The page shows no conversation */
    case Hidden = 'hidden';

    /** Its last posts and a link to the forum: writing, quoting and editing happen there */
    case Read = 'read';

    /** Its last posts and a reply box */
    case Reply = 'reply';

    public function label(): string
    {
        return __("forum::messages.settings.subject_conversation_options.{$this->value}");
    }
}
