<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

interface CategoryStatsQueryInterface
{
    /**
     * @param  list<string>  $categoryIds
     * @return array<string, CategoryStatsDTO> by category id; unknown ids are left out
     */
    public function statsOf(array $categoryIds): array;
}
