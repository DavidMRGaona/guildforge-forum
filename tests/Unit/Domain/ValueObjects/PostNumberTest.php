<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\ValueObjects\PostNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostNumberTest extends TestCase
{
    public function test_the_first_number_is_one(): void
    {
        $this->assertSame(1, PostNumber::first()->value);
    }

    public function test_the_next_number_follows_without_a_gap(): void
    {
        $this->assertSame(2, PostNumber::first()->next()->value);
        $this->assertSame(8, (new PostNumber(7))->next()->value);
    }

    public function test_the_number_after_none_is_the_first(): void
    {
        $this->assertSame(1, PostNumber::after(null)->value);
        $this->assertSame(5, PostNumber::after(new PostNumber(4))->value);
    }

    public function test_numbers_compare_by_value(): void
    {
        $this->assertTrue((new PostNumber(3))->equals(new PostNumber(3)));
        $this->assertFalse((new PostNumber(3))->equals(new PostNumber(4)));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function notPositive(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('notPositive')]
    public function test_a_number_is_positive(int $value): void
    {
        $this->expectException(InvalidPostException::class);

        new PostNumber($value);
    }
}
