<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

/**
 * An account as the editor offers it to mention.
 */
final readonly class AccountSummaryDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $avatarUrl,
    ) {}
}
