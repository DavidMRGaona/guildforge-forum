<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Search;

/**
 * A page of the forum's own search.
 */
final readonly class ForumSearchPageDTO
{
    /**
     * @param  list<ForumSearchHitDTO>  $hits  the hits of this page
     */
    public function __construct(
        public array $hits,
        public int $total,
        public int $page,
        public int $pages,
        public bool $truncated,
        public bool $failed,
    ) {}

    public static function failed(): self
    {
        return new self([], 0, 1, 1, false, true);
    }
}
