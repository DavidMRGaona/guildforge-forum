<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Dice;

use Modules\Forum\Domain\Dice\Parser;
use Modules\Forum\Domain\Dice\Roller;
use Modules\Forum\Infrastructure\Dice\RandomIntNumbers;
use PHPUnit\Framework\TestCase;

final class RandomIntNumbersTest extends TestCase
{
    public function test_every_face_comes_up_about_as_often_as_the_others(): void
    {
        $roller = new Roller(new RandomIntNumbers);
        $expression = (new Parser)->parse('1d6');
        $throws = 12_000;
        $counts = array_fill(1, 6, 0);

        for ($i = 0; $i < $throws; $i++) {
            $counts[$roller->roll($expression)->total]++;
        }

        // Each face expects 2000 throws with a deviation of ~41: ±400 is almost 10 deviations,
        // so only a biased generator fails, never luck
        foreach ($counts as $face => $count) {
            $this->assertEqualsWithDelta(2_000, $count, 400, "Face {$face} came up {$count} times in {$throws}");
        }
    }

    public function test_it_stays_inside_the_range_including_both_ends(): void
    {
        $numbers = new RandomIntNumbers;
        $seen = [];

        for ($i = 0; $i < 600; $i++) {
            $value = $numbers->between(-1, 1);
            $this->assertGreaterThanOrEqual(-1, $value);
            $this->assertLessThanOrEqual(1, $value);
            $seen[$value] = true;
        }

        $this->assertCount(3, $seen);
    }
}
