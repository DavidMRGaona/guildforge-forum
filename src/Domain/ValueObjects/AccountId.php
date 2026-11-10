<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Ramsey\Uuid\Uuid;

/**
 * Account ids as the forum keeps them: the core's user UUIDs, lowercase, so comparisons
 * never depend on how a caller spelled them.
 */
final class AccountId
{
    /**
     * The id in lowercase, or null when it is not a UUID.
     */
    public static function normalize(string $value): ?string
    {
        return Uuid::isValid($value) ? strtolower($value) : null;
    }
}
