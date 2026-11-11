<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Entities\Suspension;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\ValueObjects\SuspensionId;
use PHPUnit\Framework\TestCase;

final class SuspensionTest extends TestCase
{
    private const string USER = '0d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f';

    public function test_it_holds_from_its_start_until_its_end_and_ends_by_itself(): void
    {
        $suspension = $this->suspension('2026-11-18 10:00:00');

        $this->assertFalse($suspension->isActiveAt(new DateTimeImmutable('2026-11-11 09:59:59')));
        $this->assertTrue($suspension->isActiveAt(new DateTimeImmutable('2026-11-11 10:00:00')));
        $this->assertTrue($suspension->isActiveAt(new DateTimeImmutable('2026-11-18 09:59:59')));
        $this->assertFalse($suspension->isActiveAt(new DateTimeImmutable('2026-11-18 10:00:00')));
    }

    public function test_without_an_end_it_holds_until_lifted(): void
    {
        $suspension = $this->suspension(null);
        $this->assertTrue($suspension->isActiveAt(new DateTimeImmutable('2030-01-01')));

        $suspension->lift('1d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f', new DateTimeImmutable('2026-11-12 10:00:00'));

        $this->assertFalse($suspension->isActiveAt(new DateTimeImmutable('2026-11-12 10:00:01')));

        $this->expectException(InvalidModerationRecordException::class);
        $suspension->lift(null, new DateTimeImmutable);
    }

    public function test_it_needs_a_reason_and_an_end_after_its_start(): void
    {
        foreach ([['   ', null], ['Insultos', '2026-11-11 10:00:00']] as [$reason, $endsAt]) {
            try {
                new Suspension(SuspensionId::generate(), self::USER, null, $reason, new DateTimeImmutable('2026-11-11 10:00:00'), $endsAt === null ? null : new DateTimeImmutable($endsAt));
                $this->fail('An invalid suspension was made.');
            } catch (InvalidModerationRecordException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function suspension(?string $endsAt): Suspension
    {
        return new Suspension(SuspensionId::generate(), self::USER, null, 'Insultos', new DateTimeImmutable('2026-11-11 10:00:00'), $endsAt === null ? null : new DateTimeImmutable($endsAt));
    }
}
