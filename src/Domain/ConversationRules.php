<?php

declare(strict_types=1);

namespace Modules\Forum\Domain;

/**
 * The fixed numbers of private conversations, as the association decided them (forum#18): they
 * are not settings, and the help guide states them.
 */
final class ConversationRules
{
    /** People in a conversation, its starter included */
    public const int MIN_PARTICIPANTS = 2;

    public const int MAX_PARTICIPANTS = 10;

    /** New conversations one person may start in 24 hours; replying does not count */
    public const int STARTS_PER_DAY = 5;

    public const int STARTS_WINDOW_HOURS = 24;

    /** Messages copied before and after a reported one, for moderation to read it in context */
    public const int REPORT_CONTEXT = 3;
}
