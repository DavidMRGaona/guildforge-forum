<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * What an entry of a private conversation is: a message, or a note that someone was added or
 * left. Notes keep the numbering going but never make a conversation unread.
 */
enum ConversationEntryKind: string
{
    case Message = 'message';

    /** Its author added the person in `subject_user_id` */
    case Added = 'added';

    /** Its author left */
    case Left = 'left';
}
