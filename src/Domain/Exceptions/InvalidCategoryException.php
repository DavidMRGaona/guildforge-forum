<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use DomainException;

final class InvalidCategoryException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function invalidSlug(string $slug): self
    {
        return new self("'{$slug}' is not a category slug: lowercase letters and digits joined by single dashes, 64 characters at most.");
    }

    public static function blankName(): self
    {
        return new self('A category needs a name.');
    }

    public static function nameTooLong(int $max): self
    {
        return new self("A category name has {$max} characters at most.");
    }

    public static function descriptionTooLong(int $max): self
    {
        return new self("A category description has {$max} characters at most.");
    }

    public static function negativePosition(int $position): self
    {
        return new self("A category position is not negative ({$position} given).");
    }
}
