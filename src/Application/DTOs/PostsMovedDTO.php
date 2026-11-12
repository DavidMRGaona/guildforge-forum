<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\Entities\Discussion;

/**
 * Where moderation moved posts: the discussion they are in now (the new one of a split, or the
 * one a merge joined), the number each numbered post took by the one it had, and how many
 * posts awaiting approval went with them.
 */
final readonly class PostsMovedDTO
{
    /**
     * @param  array<int, int>  $numbers  new number by old number
     */
    public function __construct(
        public Discussion $discussion,
        public array $numbers,
        public int $pending = 0,
    ) {}

    public function count(): int
    {
        return count($this->numbers) + $this->pending;
    }
}
