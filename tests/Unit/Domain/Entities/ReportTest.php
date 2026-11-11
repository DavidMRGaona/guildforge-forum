<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Entities\Report;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\ReportId;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    private const string REPORTER = '0d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f';

    private const string MODERATOR = '1d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f';

    public function test_a_report_is_open_until_a_moderator_resolves_it(): void
    {
        $report = $this->report('  Lo ha copiado de otro foro  ');
        $this->assertTrue($report->isOpen());
        $this->assertSame('Lo ha copiado de otro foro', $report->note());

        $report->resolve(ReportResolution::NoAction, self::MODERATOR, new DateTimeImmutable('2026-11-11 10:00:00'));

        $this->assertFalse($report->isOpen());
        $this->assertSame(ReportResolution::NoAction, $report->resolution());
        $this->assertSame(self::MODERATOR, $report->resolvedBy());
    }

    public function test_a_report_is_resolved_once(): void
    {
        $report = $this->report(null);
        $report->resolve(ReportResolution::Hidden, self::MODERATOR, new DateTimeImmutable);

        $this->expectException(InvalidModerationRecordException::class);
        $report->resolve(ReportResolution::Edited, self::MODERATOR, new DateTimeImmutable);
    }

    public function test_a_blank_note_is_none_and_a_long_one_is_refused(): void
    {
        $this->assertNull($this->report('   ')->note());

        $this->expectException(InvalidModerationRecordException::class);
        $this->report(str_repeat('a', Report::MAX_NOTE_LENGTH + 1));
    }

    private function report(?string $note): Report
    {
        return Report::file(ReportId::generate(), PostId::generate(), strtoupper(self::REPORTER), ReportReason::Spam, $note, new DateTimeImmutable('2026-11-11 09:00:00'));
    }
}
