<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\Entities\Discussion;

/**
 * A run of numbers whose posts moderation moved to the same discussion, as a page shows it in
 * their place: «N mensajes movidos a …». The discussion is left out for whoever may not read it
 * (or once it is gone); `targetNumber` is where the first of them is there.
 */
final readonly class MovedPostsDTO
{
    public function __construct(
        public int $fromNumber,
        public int $throughNumber,
        public int $count,
        public ?Discussion $target,
        public ?int $targetNumber,
    ) {}
}
