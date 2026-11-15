<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Application\Queries\ProfilePostRowDTO;
use Modules\Forum\Domain\Entities\Category;

/**
 * One of a person's latest messages on their profile: the row, its category and an excerpt in
 * plain text.
 */
final readonly class ProfilePostDTO
{
    public function __construct(
        public ProfilePostRowDTO $row,
        public Category $category,
        public string $excerpt,
    ) {}
}
