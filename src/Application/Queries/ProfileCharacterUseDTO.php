<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * The characters a person's visible messages speak as, in some categories: their ids, and
 * whether any message speaks, or spoke, as a character at all (one deleted since included).
 */
final readonly class ProfileCharacterUseDTO
{
    /**
     * @param  list<string>  $characterIds
     */
    public function __construct(
        public array $characterIds,
        public bool $spokeAsCharacter,
    ) {}
}
