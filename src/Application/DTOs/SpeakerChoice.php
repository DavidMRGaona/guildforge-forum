<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\ValueObjects\CharacterId;

/**
 * Who an edited post speaks as once saved: as it did (a moderator's edit, or a client that
 * does not say), as a character, or out of character. Checked before it reaches the writer.
 */
final readonly class SpeakerChoice
{
    private function __construct(
        public bool $keep,
        public ?CharacterId $characterId,
    ) {}

    public static function keep(): self
    {
        return new self(true, null);
    }

    /** As the character, or out of character with null */
    public static function as(?CharacterId $characterId): self
    {
        return new self(false, $characterId);
    }
}
