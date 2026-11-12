<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

/**
 * A character that does not exist, or that the person may not see (answered the same way).
 */
final class CharacterNotFoundException extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Forum character {$id} does not exist.");
    }
}
