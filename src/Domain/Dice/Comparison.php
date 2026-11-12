<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Dice;

/**
 * A test on one die: `>=7`, `<3`, `=1`…
 */
final readonly class Comparison
{
    public function __construct(
        public string $operator,
        public int $value,
    ) {}

    public function matches(int $face): bool
    {
        return match ($this->operator) {
            '>=' => $face >= $this->value,
            '<=' => $face <= $this->value,
            '>' => $face > $this->value,
            '<' => $face < $this->value,
            default => $face === $this->value,
        };
    }

    public function canonical(): string
    {
        return $this->operator.$this->value;
    }
}
