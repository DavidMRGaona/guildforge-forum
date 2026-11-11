<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

/**
 * Someone posted more than the forum settings allow in a minute, or started more discussions
 * than they allow in an hour.
 */
final class PostingTooFastException extends RuntimeException
{
    private function __construct(
        public readonly int $retryAfterSeconds,
        public readonly bool $discussions,
    ) {
        parent::__construct(($discussions ? 'Starting discussions' : 'Posting').' too fast: wait '.$retryAfterSeconds.' seconds.');
    }

    public static function retryIn(int $seconds, bool $discussions = false): self
    {
        return new self(max(1, $seconds), $discussions);
    }
}
