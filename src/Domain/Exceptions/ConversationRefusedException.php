<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

/**
 * Something a person may not do in a private conversation, with the reason the page shows.
 * The reasons never say who blocked whom: a block reads the same as anyone else who may not
 * be written to («cannot_write», «unavailable_people»).
 */
final class ConversationRefusedException extends RuntimeException
{
    /** Suspended in the forum, deactivated or anonymized: they read, but do not write */
    public const string BARRED = 'barred';

    /** Someone in the conversation does not take messages from the person (a block) */
    public const string CANNOT_WRITE = 'cannot_write';

    /** Nobody else is left in the conversation */
    public const string ALONE = 'alone';

    /** Some of the people chosen cannot take part (unknown, barred, the person themself, or a block) */
    public const string UNAVAILABLE_PEOPLE = 'unavailable_people';

    /** Too few or too many people */
    public const string PARTICIPANTS = 'participants';

    /** The conversation holds as many people as it may */
    public const string FULL = 'full';

    /** The person already started as many conversations as they may in a day */
    public const string DAILY_LIMIT = 'daily_limit';

    /** Already in the conversation */
    public const string ALREADY_IN = 'already_in';

    /** Not a message the person may delete or report */
    public const string NOT_ALLOWED = 'not_allowed';

    /** Someone chosen has no public name: their account name would show to the others */
    public const string PEOPLE_WITHOUT_PUBLIC_NAME = 'people_without_public_name';

    /** The person being added has no public name */
    public const string PERSON_WITHOUT_PUBLIC_NAME = 'person_without_public_name';

    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(string $reason): self
    {
        return new self($reason, "Refused in a conversation: {$reason}.");
    }
}
