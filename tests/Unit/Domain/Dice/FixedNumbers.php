<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Dice;

use Modules\Forum\Domain\Dice\NumberGenerator;
use RuntimeException;

/**
 * Hands out the given numbers in order, whatever the range, and fails when asked for more.
 * Each one is checked against the range, so a test cannot pass with dice that do not exist.
 */
final class FixedNumbers implements NumberGenerator
{
    /** @var list<array{int, int}> */
    public array $asked = [];

    /**
     * @param  list<int>  $values
     */
    public function __construct(private array $values) {}

    public function between(int $min, int $max): int
    {
        $this->asked[] = [$min, $max];
        $value = array_shift($this->values) ?? throw new RuntimeException('More dice than expected');

        if ($value < $min || $value > $max) {
            throw new RuntimeException("{$value} is not in {$min}..{$max}");
        }

        return $value;
    }

    public function left(): int
    {
        return count($this->values);
    }
}
