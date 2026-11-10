<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategorySlugTest extends TestCase
{
    public function test_a_kebab_case_slug_is_kept(): void
    {
        $this->assertSame('partidas-por-foro', (new CategorySlug('partidas-por-foro'))->value);
        $this->assertSame('d20', (string) new CategorySlug('d20'));
    }

    public function test_a_slug_is_made_from_a_name(): void
    {
        $this->assertSame('partidas-por-foro', CategorySlug::fromName('Partidas por foro')->value);
        $this->assertSame('cronicas-de-campana', CategorySlug::fromName('  Crónicas de campaña! ')->value);
    }

    public function test_a_name_without_letters_or_digits_gives_no_slug(): void
    {
        $this->expectException(InvalidCategoryException::class);

        CategorySlug::fromName('¡¿?!');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'capitals' => ['General'];
        yield 'spaces' => ['dos palabras'];
        yield 'leading dash' => ['-general'];
        yield 'trailing dash' => ['general-'];
        yield 'double dash' => ['a--b'];
        yield 'accent' => ['campaña'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[DataProvider('invalid')]
    public function test_a_slug_is_lowercase_kebab_case_of_64_characters_at_most(string $value): void
    {
        $this->expectException(InvalidCategoryException::class);

        new CategorySlug($value);
    }

    public function test_64_characters_are_accepted(): void
    {
        $this->assertSame(64, strlen((new CategorySlug(str_repeat('a', 64)))->value));
    }
}
