<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Dice;

use Modules\Forum\Domain\Dice\Limits;
use Modules\Forum\Domain\Dice\Parser;
use Modules\Forum\Domain\Dice\Roller;
use Modules\Forum\Domain\Dice\RollResult;
use PHPUnit\Framework\TestCase;

final class RollerTest extends TestCase
{
    /**
     * @param  list<int>  $faces
     */
    private function roll(string $text, array $faces): RollResult
    {
        $numbers = new FixedNumbers($faces);
        $result = (new Roller($numbers))->roll((new Parser)->parse($text));

        $this->assertSame(0, $numbers->left(), 'Every number handed out was used');

        return $result;
    }

    /**
     * @return list<int>
     */
    private function values(RollResult $result, int $term = 0): array
    {
        return array_column($result->terms[$term]['dice'], 'v');
    }

    /**
     * @return list<int>
     */
    private function kept(RollResult $result, int $term = 0): array
    {
        return array_column(array_filter($result->terms[$term]['dice'], static fn (array $die): bool => $die['k']), 'v');
    }

    public function test_it_sums_dice_and_constants(): void
    {
        $result = $this->roll('2d6+3', [4, 2]);

        $this->assertSame(9, $result->total);
        $this->assertSame([4, 2], $this->values($result));
        $this->assertSame(6, $result->terms[0]['subtotal']);
        $this->assertSame(['sign' => 1, 'kind' => 'const', 'value' => 3], $result->terms[1]);
    }

    public function test_it_subtracts_dice_and_constants(): void
    {
        $this->assertSame(5, $this->roll('2d6-1d4-3', [6, 5, 3])->total);
    }

    public function test_it_adds_two_groups_of_dice(): void
    {
        $this->assertSame(10, $this->roll('3d6+1d4', [1, 2, 3, 4])->total);
    }

    public function test_it_asks_for_numbers_in_the_range_of_each_die(): void
    {
        $numbers = new FixedNumbers([1, 100, 0, -1]);
        $roller = new Roller($numbers);

        $roller->roll((new Parser)->parse('d%+1d100'));
        $roller->roll((new Parser)->parse('2dF'));

        $this->assertSame([[1, 100], [1, 100], [-1, 1], [-1, 1]], $numbers->asked);
    }

    public function test_fate_dice_add_up_with_their_sign(): void
    {
        $this->assertSame(0, $this->roll('4dF+1', [-1, -1, 1, 0])->total);
    }

    public function test_a_pool_counts_the_dice_that_reach_the_threshold(): void
    {
        $result = $this->roll('5d10>=7', [10, 7, 6, 1, 9]);

        $this->assertSame(3, $result->total);
        $this->assertSame('successes', $result->terms[0]['mode']);
    }

    public function test_a_pool_follows_every_comparison(): void
    {
        $this->assertSame(2, $this->roll('4d10>7', [10, 7, 8, 1])->total);
        $this->assertSame(2, $this->roll('4d10<=2', [10, 2, 8, 1])->total);
        $this->assertSame(1, $this->roll('4d10<2', [10, 2, 8, 1])->total);
        $this->assertSame(2, $this->roll('4d10=2', [2, 2, 8, 1])->total);
    }

    public function test_the_failures_of_a_pool_are_subtracted(): void
    {
        $this->assertSame(2, $this->roll('5d10>=8f=1', [10, 1, 9, 8, 4])->total);
    }

    public function test_a_pool_can_go_negative(): void
    {
        $this->assertSame(-2, $this->roll('3d10>=8f=1', [1, 1, 5])->total);
    }

    public function test_keep_highest_marks_the_rest_as_dropped(): void
    {
        $result = $this->roll('4d6kh3', [2, 6, 3, 5]);

        $this->assertSame(14, $result->total);
        $this->assertSame([6, 3, 5], $this->kept($result));
        $this->assertFalse($result->terms[0]['dice'][0]['k']);
    }

    public function test_keep_lowest_is_disadvantage(): void
    {
        $this->assertSame(4, $this->roll('2d20kl1', [17, 4])->total);
    }

    public function test_drop_lowest_and_drop_highest(): void
    {
        $this->assertSame(14, $this->roll('4d6dl1', [2, 6, 3, 5])->total);
        $this->assertSame(10, $this->roll('4d6dh1', [2, 6, 3, 5])->total);
    }

    public function test_a_tie_keeps_the_earlier_die(): void
    {
        $result = $this->roll('3d6kh2', [4, 4, 4]);

        $this->assertSame([true, true, false], array_column($result->terms[0]['dice'], 'k'));
    }

    public function test_explosions_add_dice_marked_as_exploded(): void
    {
        $result = $this->roll('2d6!', [6, 2, 6, 3]);

        $this->assertSame([6, 2, 6, 3], $this->values($result));
        $this->assertSame([false, false, true, true], array_column($result->terms[0]['dice'], 'x'));
        $this->assertSame(17, $result->total);
    }

    public function test_an_exploded_die_can_explode_again(): void
    {
        $this->assertSame([6, 6, 6, 1], $this->values($this->roll('1d6!', [6, 6, 6, 1])));
    }

    public function test_explosions_follow_the_threshold(): void
    {
        $this->assertSame([9, 4], $this->values($this->roll('1d10!>=9', [9, 4])));
    }

    public function test_explosions_stop_at_the_cap(): void
    {
        $result = $this->roll('1d2!', array_fill(0, 1 + Limits::MAX_EXPLOSION_EXTRA, 2));

        $this->assertCount(1 + Limits::MAX_EXPLOSION_EXTRA, $result->terms[0]['dice']);
    }

    public function test_keeping_after_explosions_chooses_among_all_the_dice(): void
    {
        $this->assertSame([6], $this->kept($this->roll('2d6!kh1', [6, 1, 3])));
    }

    public function test_a_pool_counts_only_the_kept_dice(): void
    {
        $this->assertSame(2, $this->roll('4d10kh2>=7', [9, 8, 2, 3])->total);
    }

    public function test_a_result_survives_being_stored(): void
    {
        $result = $this->roll('2d6+3', [4, 2]);

        $this->assertEquals($result, RollResult::fromArray(json_decode(json_encode($result->toArray(), JSON_THROW_ON_ERROR), true)));
    }

    public function test_something_else_is_not_a_result(): void
    {
        $this->assertNull(RollResult::fromArray(null));
        $this->assertNull(RollResult::fromArray('{}'));
        $this->assertNull(RollResult::fromArray(['terms' => []]));
        $this->assertNull(RollResult::fromArray(['terms' => [], 'total' => '3']));
    }
}
