<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

final readonly class DiscussionListPageDTO
{
    /**
     * @param  list<DiscussionRowDTO>  $pinned  only on the first page of a list with pinned discussions first
     * @param  list<DiscussionRowDTO>  $items
     */
    public function __construct(
        public array $pinned,
        public array $items,
        public ?DiscussionCursor $next,
    ) {}
}
