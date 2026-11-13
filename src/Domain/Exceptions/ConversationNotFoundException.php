<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

/**
 * A conversation (or one of its messages, or a report about one) that does not exist or that the
 * person may not read. Both answer the same, so nobody learns that a conversation exists.
 */
final class ConversationNotFoundException extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Forum conversation {$id} does not exist.");
    }
}
