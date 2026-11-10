<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\Enums\CategoryIcon;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\ValueObjects\CategoryAppearance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryAppearanceTest extends TestCase
{
    public function test_a_colour_is_kept_in_lowercase(): void
    {
        $appearance = new CategoryAppearance('#A1B2C3', CategoryIcon::Dice);

        $this->assertSame('#a1b2c3', $appearance->color);
        $this->assertSame(CategoryIcon::Dice, $appearance->icon);
    }

    public function test_both_are_optional(): void
    {
        $this->assertNull(CategoryAppearance::plain()->color);
        $this->assertNull(CategoryAppearance::plain()->icon);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidColours(): iterable
    {
        yield 'name' => ['red'];
        yield 'short form' => ['#abc'];
        yield 'no hash' => ['a1b2c3'];
        yield 'css' => ['#a1b2c3;background:url(x)'];
    }

    #[DataProvider('invalidColours')]
    public function test_a_colour_is_six_digit_hex(string $colour): void
    {
        $this->expectException(InvalidCategoryException::class);

        new CategoryAppearance($colour, null);
    }
}
