<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use DateTimeImmutable;

/**
 * One version of a post, as moderators see its history: who wrote that version and when, and
 * the character it spoke as (null out of character, or once that character was deleted).
 */
final readonly class PostVersionDTO
{
    public function __construct(
        public int $number,
        public string $html,
        public ?string $authorName,
        public DateTimeImmutable $writtenAt,
        public bool $isCurrent,
        public ?string $characterName = null,
    ) {}
}
