<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Dice;

use Modules\Forum\Domain\Dice\NumberGenerator;

/**
 * The numbers of real rolls: random_int, which draws from the operating system's secure
 * source, so no one can predict or steer a die.
 */
final class RandomIntNumbers implements NumberGenerator
{
    public function between(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
