<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Dice;

/**
 * Where the numbers of a roll come from. The real one is cryptographic (random_int); tests give
 * a fixed sequence, so every form of the notation can be checked without luck.
 */
interface NumberGenerator
{
    /**
     * A whole number between $min and $max, both included.
     */
    public function between(int $min, int $max): int;
}
