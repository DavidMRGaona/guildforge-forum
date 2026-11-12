<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * What the tables say today about a search hit that is still there to read: the discussion's category and title,
 * and whether its opening message is still visible (a discussion hit shows a text from that message).
 */
final readonly class VisibleSearchHitDTO
{
    public function __construct(
        public string $categoryId,
        public string $title,
        public bool $openingVisible,
    ) {}
}
