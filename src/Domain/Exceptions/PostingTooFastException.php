<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

/**
 * Someone posted more than the forum settings allow in a minute.
 */
final class PostingTooFastException extends RuntimeException
{
    private function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct("Posting too fast: wait {$retryAfterSeconds} seconds.");
    }

    public static function retryIn(int $seconds): self
    {
        return new self(max(1, $seconds));
    }
}
