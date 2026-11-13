<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Dice\Parser;
use Modules\Forum\Domain\Dice\Roller;
use Modules\Forum\Domain\Entities\DiceRoll;
use Modules\Forum\Domain\Exceptions\RollRevealException;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Unit\Domain\Dice\FixedNumbers;
use PHPUnit\Framework\TestCase;

/**
 * A hidden roll keeps its result secret until someone reveals it, once and for good.
 */
final class DiceRollTest extends TestCase
{
    private const string ROLLER = '0b5c1d2e-3f4a-4b5c-8d6e-7f8a9b0c1d2e';

    private const string REVEALER = '1c6d2e3f-4a5b-4c6d-9e7f-8a9b0c1d2e3f';

    public function test_a_public_roll_keeps_nothing_secret(): void
    {
        $roll = $this->thrown(hidden: false);

        $this->assertFalse($roll->hidden);
        $this->assertFalse($roll->isSecret());
        $this->assertSame(self::ROLLER, $roll->rolledBy);
    }

    public function test_a_hidden_roll_is_secret_until_it_is_revealed(): void
    {
        $roll = $this->thrown(hidden: true);
        $this->assertTrue($roll->isSecret());

        $revealed = $roll->revealedBy(self::REVEALER, new DateTimeImmutable('2026-11-13 20:00:00'));

        $this->assertTrue($revealed->hidden);
        $this->assertFalse($revealed->isSecret());
        $this->assertSame(self::REVEALER, $revealed->revealedBy);
        $this->assertSame('2026-11-13 20:00:00', $revealed->revealedAt?->format('Y-m-d H:i:s'));
        $this->assertSame(9, $revealed->result?->total, 'Revealing never throws again.');
    }

    public function test_a_roll_is_revealed_only_once(): void
    {
        $revealed = $this->thrown(hidden: true)->revealedBy(self::REVEALER, new DateTimeImmutable);

        $this->expectException(RollRevealException::class);
        $revealed->revealedBy(self::ROLLER, new DateTimeImmutable);
    }

    public function test_a_public_roll_has_nothing_to_reveal(): void
    {
        $this->expectException(RollRevealException::class);
        $this->thrown(hidden: false)->revealedBy(self::ROLLER, new DateTimeImmutable);
    }

    public function test_a_removed_roll_cannot_be_revealed(): void
    {
        $removed = $this->thrown(hidden: true)->removedAt(new DateTimeImmutable);

        $this->expectException(RollRevealException::class);
        $removed->revealedBy(self::ROLLER, new DateTimeImmutable);
    }

    public function test_moving_or_removing_a_hidden_roll_keeps_it_hidden(): void
    {
        $roll = $this->thrown(hidden: true)->movedTo(3)->removedAt(new DateTimeImmutable);

        $this->assertTrue($roll->isSecret());
        $this->assertSame(self::ROLLER, $roll->rolledBy);
    }

    public function test_a_hidden_roll_that_was_not_thrown_is_still_hidden(): void
    {
        $roll = DiceRoll::refused('e1f2a3b4-c5d6-4e7f-8a9b-0c1d2e3f4a5b', PostId::generate(), 1, '2d6+', 'syntax', false, new DateTimeImmutable, hidden: true, rolledBy: self::ROLLER);

        $this->assertTrue($roll->isSecret());
    }

    private function thrown(bool $hidden): DiceRoll
    {
        $expression = (new Parser)->parse('2d6+3');

        return DiceRoll::thrown(
            'f0e1d2c3-b4a5-4968-8776-655443322110',
            PostId::generate(),
            1,
            $expression->canonical(),
            $expression->diceCount(),
            (new Roller(new FixedNumbers([4, 2])))->roll($expression),
            false,
            new DateTimeImmutable('2026-11-13 18:00:00'),
            hidden: $hidden,
            rolledBy: self::ROLLER,
        );
    }
}
