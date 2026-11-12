<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Dice;

/**
 * One piece of an expression: a group of dice or a constant, added or subtracted.
 */
interface Term
{
    /**
     * @return 1|-1
     */
    public function sign(): int;

    /** Dice the term throws, before explosions */
    public function diceCount(): int;

    /** The term without its sign, in a fixed spelling */
    public function canonical(): string;
}
