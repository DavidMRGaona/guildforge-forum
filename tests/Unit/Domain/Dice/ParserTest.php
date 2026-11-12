<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Dice;

use Modules\Forum\Domain\Dice\InvalidNotation;
use Modules\Forum\Domain\Dice\Limits;
use Modules\Forum\Domain\Dice\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valid(): iterable
    {
        yield 'dice and constant' => ['2d6+3', '2d6+3'];
        yield 'one die' => ['d20', '1d20'];
        yield 'case does not matter' => ['2D6 + 3', '2d6+3'];
        yield 'spaces around operators' => ['1d20 + 5 - 2', '1d20+5-2'];
        yield 'surrounding spaces' => ["  1d6 \n", '1d6'];
        yield 'subtraction' => ['1d20-1', '1d20-1'];
        yield 'two groups of dice' => ['3d6+1d4', '3d6+1d4'];
        yield 'subtraction first group' => ['3d6-1d4', '3d6-1d4'];
        yield 'percentile' => ['d%', '1d100'];
        yield 'percentile group' => ['2d%+1', '2d100+1'];
        yield 'fate dice' => ['4dF', '4df'];
        yield 'pool' => ['5d10>=7', '5d10>=7'];
        yield 'pool with every comparison' => ['5d10>7', '5d10>7'];
        yield 'pool with less or equal' => ['5d10<=3', '5d10<=3'];
        yield 'pool with less' => ['5d10<3', '5d10<3'];
        yield 'pool with equal' => ['6d6=6', '6d6=6'];
        yield 'pool with double equal' => ['6d6==6', '6d6=6'];
        yield 'pool with failures' => ['5d10>=8f=1', '5d10>=8f=1'];
        yield 'keep highest' => ['4d6kh3', '4d6kh3'];
        yield 'k means keep highest' => ['2d20k', '2d20kh1'];
        yield 'keep lowest' => ['2d20kl1', '2d20kl1'];
        yield 'drop lowest' => ['4d6dl1', '4d6dl1'];
        yield 'drop highest' => ['4d6dh1', '4d6dh1'];
        yield 'drop highest defaults to one' => ['3d6dh', '3d6dh1'];
        yield 'explosion on the maximum' => ['3d6!', '3d6!=6'];
        yield 'explosion with threshold' => ['3d10!>=9', '3d10!>=9'];
        yield 'explosion and keep' => ['4d6!kh3', '4d6!=6kh3'];
        yield 'pool and explosion' => ['5d10!>=7', '5d10!>=7'];
        yield 'constant alone' => ['7', '7'];
    }

    #[DataProvider('valid')]
    public function test_it_reads_valid_expressions(string $input, string $canonical): void
    {
        $this->assertSame($canonical, (new Parser)->parse($input)->canonical());
    }

    public function test_two_spellings_of_the_same_dice_share_the_canonical_form(): void
    {
        $this->assertSame(
            (new Parser)->parse('2D6 + 3')->canonical(),
            (new Parser)->parse('2d6+3')->canonical(),
        );
    }

    public function test_it_counts_the_dice_before_explosions(): void
    {
        $this->assertSame(7, (new Parser)->parse('3d6+4d10!+2')->diceCount());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => ['', InvalidNotation::EMPTY];
        yield 'blank' => ['   ', InvalidNotation::EMPTY];
        yield 'only text' => ['ataque', InvalidNotation::SYNTAX];
        yield 'a reason is not part of it' => ['2d6+3 Ataque', InvalidNotation::SYNTAX];
        yield 'glued text' => ['2d6fuerza', InvalidNotation::BAD_MODIFIER];
        yield 'text in place of a term' => ['1d20 + daño', InvalidNotation::SYNTAX];
        yield 'trailing operator' => ['1d20+', InvalidNotation::SYNTAX];
        yield 'dice without sides' => ['2d', InvalidNotation::SYNTAX];
        yield 'zero dice' => ['0d6', InvalidNotation::BAD_DICE];
        yield 'one-sided die' => ['2d1', InvalidNotation::BAD_DICE];
        yield 'too many dice' => ['101d6', InvalidNotation::TOO_MANY_DICE];
        yield 'too many sides' => ['1d1001', InvalidNotation::TOO_MANY_SIDES];
        yield 'huge number' => ['99999999d6', InvalidNotation::NUMBER_TOO_BIG];
        yield 'constant too big' => ['1d6+10001', InvalidNotation::NUMBER_TOO_BIG];
        yield 'comparison too big' => ['5d10>=10001', InvalidNotation::NUMBER_TOO_BIG];
        yield 'too many terms' => [implode('+', array_fill(0, Limits::MAX_TERMS + 1, '1d4')), InvalidNotation::TOO_MANY_TERMS];
        yield 'keep more than rolled' => ['2d6kh3', InvalidNotation::BAD_KEEP];
        yield 'drop all' => ['2d6dl2', InvalidNotation::BAD_KEEP];
        yield 'keep zero' => ['3d6kh0', InvalidNotation::BAD_KEEP];
        yield 'two keep rules' => ['4d6kh3dl1', InvalidNotation::BAD_KEEP];
        yield 'explosion on every face' => ['3d6!>=1', InvalidNotation::BAD_EXPLOSION];
        yield 'explosion that never happens' => ['3d6!>6', InvalidNotation::BAD_EXPLOSION];
        yield 'explosion twice' => ['3d6!!', InvalidNotation::BAD_EXPLOSION];
        yield 'fate cannot explode' => ['4dF!', InvalidNotation::BAD_EXPLOSION];
        yield 'failure without success' => ['5d10f=1', InvalidNotation::BAD_MODIFIER];
        yield 'two thresholds' => ['5d10>=7>=8', InvalidNotation::BAD_MODIFIER];
        yield 'comparison without number' => ['5d10>=', InvalidNotation::BAD_MODIFIER];
        yield 'too long' => [str_repeat('1+', Limits::MAX_EXPRESSION_LENGTH), InvalidNotation::TOO_LONG];
    }

    #[DataProvider('invalid')]
    public function test_it_refuses_invalid_expressions_with_a_reason(string $input, string $reason): void
    {
        try {
            (new Parser)->parse($input);
            $this->fail('Expected an InvalidNotation');
        } catch (InvalidNotation $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    public function test_the_limits_are_inclusive(): void
    {
        $this->assertSame('100d1000', (new Parser)->parse('100d1000')->canonical());
        $this->assertSame('1d6+10000', (new Parser)->parse('1d6+10000')->canonical());
        $this->assertCount(Limits::MAX_TERMS, (new Parser)->parse(implode('+', array_fill(0, Limits::MAX_TERMS, '1d4')))->terms);
    }
}
