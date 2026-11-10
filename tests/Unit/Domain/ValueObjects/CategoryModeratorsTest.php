<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\Enums\RosterScope;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use PHPUnit\Framework\TestCase;

final class CategoryModeratorsTest extends TestCase
{
    public function test_nobody_by_default(): void
    {
        $moderators = CategoryModerators::none();

        $this->assertSame([], $moderators->roleIds);
        $this->assertNull($moderators->roster);
        $this->assertTrue($moderators->isEmpty());
    }

    public function test_roles_and_the_roster_round_trip_through_an_array(): void
    {
        $moderators = new CategoryModerators(['0B6C2A5E-1111-4AAA-8AAA-000000000001'], RosterScope::Leaders);

        $this->assertSame(['0b6c2a5e-1111-4aaa-8aaa-000000000001'], $moderators->roleIds);
        $this->assertFalse($moderators->isEmpty());
        $this->assertEquals($moderators, CategoryModerators::fromArray($moderators->toArray()));
    }

    public function test_role_ids_are_uuids(): void
    {
        $this->expectException(InvalidCategoryException::class);

        new CategoryModerators(['moderacion']);
    }

    public function test_an_unreadable_array_is_refused(): void
    {
        $this->expectException(InvalidCategoryException::class);

        CategoryModerators::fromArray(['role_ids' => 'todos']);
    }
}
