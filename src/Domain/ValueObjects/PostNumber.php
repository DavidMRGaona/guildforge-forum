<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidPostException;

/**
 * A post's place in its discussion, from 1. A post gets it when it first becomes readable
 * (published, or approved) and keeps it when it is hidden or deleted, so readers always see
 * 1, 2, 3… with a placeholder instead of a gap.
 */
final readonly class PostNumber
{
    /**
     * @throws InvalidPostException when the value is not positive
     */
    public function __construct(
        public int $value,
    ) {
        if ($value < 1) {
            throw InvalidPostException::numberNotPositive($value);
        }
    }

    public static function first(): self
    {
        return new self(1);
    }

    /**
     * The number that follows the highest one given out so far (none: the first).
     */
    public static function after(?self $highest): self
    {
        return $highest?->next() ?? self::first();
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
