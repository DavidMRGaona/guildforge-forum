<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Entities;

use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function test_a_category_keeps_its_name_slug_description_and_position(): void
    {
        $category = new Category(CategoryId::generate(), ' General ', new CategorySlug('general'), ' Para todo ', 2);

        $this->assertSame('General', $category->name());
        $this->assertSame('general', $category->slug()->value);
        $this->assertSame('Para todo', $category->description());
        $this->assertSame(2, $category->position());
    }

    public function test_a_blank_description_is_none(): void
    {
        $this->assertNull((new Category(CategoryId::generate(), 'General', new CategorySlug('general'), '  '))->description());
    }

    public function test_the_name_is_required(): void
    {
        $this->expectException(InvalidCategoryException::class);

        new Category(CategoryId::generate(), '  ', new CategorySlug('general'));
    }

    public function test_the_name_has_a_limit(): void
    {
        $this->expectException(InvalidCategoryException::class);

        new Category(CategoryId::generate(), str_repeat('a', Category::MAX_NAME_LENGTH + 1), new CategorySlug('general'));
    }

    public function test_the_description_has_a_limit(): void
    {
        $this->expectException(InvalidCategoryException::class);

        new Category(CategoryId::generate(), 'General', new CategorySlug('general'), str_repeat('a', Category::MAX_DESCRIPTION_LENGTH + 1));
    }

    public function test_the_position_is_not_negative(): void
    {
        $this->expectException(InvalidCategoryException::class);

        new Category(CategoryId::generate(), 'General', new CategorySlug('general'), null, -1);
    }

    public function test_renaming_and_moving_in_the_order(): void
    {
        $category = new Category(CategoryId::generate(), 'General', new CategorySlug('general'));

        $category->rename('Charla', new CategorySlug('charla'), 'De todo un poco');
        $category->moveToPosition(5);

        $this->assertSame('Charla', $category->name());
        $this->assertSame('charla', $category->slug()->value);
        $this->assertSame('De todo un poco', $category->description());
        $this->assertSame(5, $category->position());
    }
}
