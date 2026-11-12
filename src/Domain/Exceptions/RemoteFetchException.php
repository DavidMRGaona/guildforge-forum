<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

/**
 * Something the forum went to fetch from another site and did not take. The reason says why,
 * for the log and the tests; people only learn that it could not be fetched.
 */
final class RemoteFetchException extends RuntimeException
{
    /** The host has no address, or it could not be looked up */
    public const string UNRESOLVABLE = 'unresolvable';

    /** The host resolves to an address off the public internet */
    public const string NOT_PUBLIC = 'not_public';

    /** The connection was made to another address than the one checked */
    public const string CONNECTION_MISMATCH = 'connection_mismatch';

    /** A redirect to another host, or to an address the forum does not fetch from */
    public const string REDIRECT_ELSEWHERE = 'redirect_elsewhere';

    public const string TOO_MANY_REDIRECTS = 'too_many_redirects';

    public const string TOO_LARGE = 'too_large';

    /** Any answer but 200 */
    public const string HTTP_STATUS = 'http_status';

    /** Timeout, TLS, network or any other transfer error */
    public const string UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(string $reason, string $detail): self
    {
        return new self($reason, $detail);
    }
}
