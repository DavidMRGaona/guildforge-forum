<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

/**
 * The likes of one post as a reader sees them: how many, whether the reader gave one and the
 * accounts that gave the latest ones (newest first).
 */
final readonly class PostLikesDTO
{
    /**
     * @param  list<string>  $latestAccountIds
     */
    public function __construct(
        public int $count,
        public bool $likedByViewer,
        public array $latestAccountIds,
    ) {}

    public static function none(): self
    {
        return new self(0, false, []);
    }
}
