<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use DomainException;

/**
 * A private conversation or one of its messages that cannot be: a blank or too long title, or a
 * message in the wrong state (deleted already, or a note that is not a message).
 */
final class InvalidConversationException extends DomainException
{
    public static function blankTitle(): self
    {
        return new self('A conversation needs a title.');
    }

    public static function titleTooLong(int $max): self
    {
        return new self("A conversation title has at most {$max} characters.");
    }

    public static function notAMessage(string $id): self
    {
        return new self("Conversation entry {$id} is not a message.");
    }

    public static function notShown(string $id): self
    {
        return new self("Conversation message {$id} is deleted or hidden already.");
    }

    public static function reasonTooLong(int $max): self
    {
        return new self("A reason has at most {$max} characters.");
    }
}
