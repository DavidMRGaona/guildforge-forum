<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

/**
 * Who wrote a post and who it speaks as, as far as naming its writer goes (CharacterWriterNames).
 * Built from a post, or from a row of a list that only carries those columns.
 */
final readonly class PostWriterDTO
{
    /**
     * @param  string|null  $authorId  the account that wrote it; null once that account is gone
     * @param  string|null  $characterId  the character it speaks as; null out of character
     * @param  bool  $characterRemoved  whether it spoke as a character deleted since
     */
    public function __construct(
        public ?string $authorId,
        public ?string $characterId,
        public bool $characterRemoved,
    ) {}
}
