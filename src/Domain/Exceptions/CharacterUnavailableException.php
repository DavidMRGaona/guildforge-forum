<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

/**
 * A post that would speak as a character its author may not use there: someone else's, a
 * retired one, one tied to another category, or any while characters are off for them.
 */
final class CharacterUnavailableException extends RuntimeException
{
    public static function for(string $characterId): self
    {
        return new self("Character {$characterId} cannot be used in this post.");
    }
}
