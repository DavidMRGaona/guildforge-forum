<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\Entities\Character;

/**
 * How a post that speaks, or spoke, as a personal character names its writer to a reader
 * (CharacterWriterNames). With a public name, the account may show as always; without one,
 * nothing may point at the account: not its name, avatar, title or id.
 */
final readonly class WriterNameDTO
{
    /**
     * @param  string  $name  the public name; without one, the character's name, or a neutral label
     * @param  Character|null  $seenCharacter  the character the post speaks as, when the reader may see it
     */
    public function __construct(
        public string $name,
        public bool $isPublicName,
        public ?Character $seenCharacter,
    ) {}
}
