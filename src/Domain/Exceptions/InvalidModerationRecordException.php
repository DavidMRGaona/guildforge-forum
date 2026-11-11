<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use DomainException;

/**
 * A report or a suspension that cannot be (a note too long, an end before its start), or a
 * change to one in the wrong state (resolving a report twice, lifting a suspension twice).
 */
final class InvalidModerationRecordException extends DomainException
{
    public static function noteTooLong(int $max): self
    {
        return new self("A report's note is limited to {$max} characters.");
    }

    public static function alreadyResolved(string $id): self
    {
        return new self("Report {$id} is resolved already.");
    }

    public static function invalidAccount(string $id): self
    {
        return new self("Not an account id: {$id}");
    }

    public static function blankReason(): self
    {
        return new self('A suspension needs a reason.');
    }

    public static function reasonTooLong(int $max): self
    {
        return new self("A suspension's reason is limited to {$max} characters.");
    }

    public static function endsBeforeItStarts(): self
    {
        return new self('A suspension cannot end before it starts.');
    }

    public static function alreadyLifted(string $id): self
    {
        return new self("Suspension {$id} is lifted already.");
    }
}
