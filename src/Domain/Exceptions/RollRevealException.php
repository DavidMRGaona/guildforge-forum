<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

/**
 * A roll that cannot be revealed: it was never hidden, it is revealed already, or an edit took it
 * out of its post. $reason is a stable key for the message.
 */
final class RollRevealException extends RuntimeException
{
    public const string NOT_HIDDEN = 'not_hidden';

    public const string ALREADY_REVEALED = 'already_revealed';

    public const string REMOVED = 'removed';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notHidden(string $rollId): self
    {
        return new self("Roll {$rollId} was never hidden.", self::NOT_HIDDEN);
    }

    public static function alreadyRevealed(string $rollId): self
    {
        return new self("Roll {$rollId} is revealed already.", self::ALREADY_REVEALED);
    }

    public static function removed(string $rollId): self
    {
        return new self("Roll {$rollId} is no longer in its post.", self::REMOVED);
    }
}
