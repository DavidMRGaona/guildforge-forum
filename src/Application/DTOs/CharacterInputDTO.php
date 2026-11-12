<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

/**
 * What someone sends to create or change a character. `portrait` holds the bytes of a new
 * portrait; `removePortrait` drops the one it has (a new one wins).
 */
final readonly class CharacterInputDTO
{
    public function __construct(
        public string $name,
        public ?string $description,
        public ?string $categoryId,
        public ?string $portrait = null,
        public bool $removePortrait = false,
    ) {}
}
