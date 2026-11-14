<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\ValueObjects\MemberTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemberTitleTest extends TestCase
{
    public function test_it_trims_the_input(): void
    {
        $this->assertSame('Bardo', MemberTitle::fromInput('  Bardo  ')?->value);
    }

    public function test_blank_means_no_title(): void
    {
        $this->assertNull(MemberTitle::fromInput(''));
        $this->assertNull(MemberTitle::fromInput('   '));
        $this->assertNull(MemberTitle::fromInput(null));
    }

    public function test_it_takes_thirty_characters_counted_as_letters(): void
    {
        $thirty = str_repeat('ñ', 25).'🎲🎲🎲🎲🎲';

        $this->assertSame($thirty, MemberTitle::fromInput($thirty)?->value);

        $this->expectException(InvalidModerationRecordException::class);
        MemberTitle::fromInput($thirty.'á');
    }

    #[DataProvider('brokenTitles')]
    public function test_it_refuses_line_breaks_and_control_characters(string $title): void
    {
        $this->expectException(InvalidModerationRecordException::class);

        MemberTitle::fromInput($title);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenTitles(): array
    {
        return [
            'line break' => ["Lanzador\nde dados"],
            'carriage return' => ["Lanzador\rde dados"],
            'bell' => ["Bardo\u{0007}"],
            'tab' => ["Bardo\tJefe"],
        ];
    }

    public function test_it_keeps_markup_as_text(): void
    {
        $this->assertSame('<b>Jefe</b>', MemberTitle::fromInput('<b>Jefe</b>')?->value);
    }
}
