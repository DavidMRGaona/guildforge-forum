<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * The characters a person's messages speak as, in some categories (those counted on their profile,
 * and the first message of each topic they opened): their ids, and whether any of them speaks, or
 * spoke, as a character at all (one deleted since included).
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
