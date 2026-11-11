<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Forum\Domain\Entities\ModerationLogEntry;
use Modules\Forum\Domain\Enums\ModerationAction;
use PHPUnit\Framework\TestCase;

final class ModerationLogEntryTest extends TestCase
{
    public function test_accounts_are_kept_in_lowercase_and_a_blank_reason_is_none(): void
    {
        $entry = $this->entry('0D2F3C4B-1A2B-4C3D-8E9F-0A1B2C3D4E5F', '   ');

        $this->assertSame('0d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f', $entry->moderatorId);
        $this->assertNull($entry->reason);
    }

    public function test_a_reason_is_trimmed_and_cut_to_its_limit(): void
    {
        $entry = $this->entry(null, '  '.str_repeat('a', ModerationLogEntry::MAX_REASON_LENGTH + 20).'  ');

        $this->assertSame(ModerationLogEntry::MAX_REASON_LENGTH, mb_strlen((string) $entry->reason));
    }

    public function test_an_account_id_must_be_a_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->entry('not-an-id', null);
    }

    private function entry(?string $moderatorId, ?string $reason): ModerationLogEntry
    {
        return new ModerationLogEntry('0d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e50', ModerationAction::DiscussionClosed, $moderatorId, null, null, null, null, $reason, [], new DateTimeImmutable);
    }
}
