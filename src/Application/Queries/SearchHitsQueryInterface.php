<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * Checks search hits against the tables, whatever the index remembers.
 */
interface SearchHitsQueryInterface
{
    /**
     * The addresses (discussion number, reply number or 0 for the discussion itself) that are still readable
     * as the tables stand: the discussion is numbered, not hidden and not awaiting approval, and a reply is
     * visible. Whether the reader may read the category is decided by the caller from the category id.
     *
     * @param  list<array{int, int}>  $addresses
     * @return array<string, VisibleSearchHitDTO> by "number:reply"
     */
    public function visible(array $addresses): array;
}
