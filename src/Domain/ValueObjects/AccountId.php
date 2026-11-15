<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

/**
 * Account ids as the forum keeps them: the core's user UUIDs, lowercase, so comparisons
 * never depend on how a caller spelled them.
 */
final class AccountId
{
    private const string CANONICAL = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /**
     * The id in lowercase, or null when it is not a UUID in its canonical form: no braces, no
     * `urn:uuid:` prefix, no trailing newline (a library check would accept those and hand the
     * database a value it cannot read as a `uuid`).
     */
    public static function normalize(string $value): ?string
    {
        return preg_match(self::CANONICAL, $value) === 1 ? strtolower($value) : null;
    }
}
