<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Dice;

final readonly class ConstantTerm implements Term
{
    /**
     * @param  1|-1  $sign
     */
    public function __construct(
        private int $sign,
        public int $value,
    ) {}

    public function sign(): int
    {
        return $this->sign;
    }

    public function diceCount(): int
    {
        return 0;
    }

    public function canonical(): string
    {
        return (string) $this->value;
    }
}
