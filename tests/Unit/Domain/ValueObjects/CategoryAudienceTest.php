<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\Enums\CategoryAudienceKind;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryAudienceTest extends TestCase
{
    private const string ROLE_A = '0b6c2a5e-1111-4aaa-8aaa-000000000001';

    private const string ROLE_B = '0b6c2a5e-1111-4aaa-8aaa-000000000002';

    public function test_roles_are_kept_lowercase_sorted_and_once(): void
    {
        $audience = CategoryAudience::roles([self::ROLE_B, strtoupper(self::ROLE_A), self::ROLE_B]);

        $this->assertSame(CategoryAudienceKind::Roles, $audience->kind);
        $this->assertSame([self::ROLE_A, self::ROLE_B], $audience->roleIds);
    }

    public function test_a_roles_audience_names_at_least_one_role(): void
    {
        $this->expectException(InvalidCategoryException::class);

        CategoryAudience::roles([]);
    }

    public function test_role_ids_are_uuids(): void
    {
        $this->expectException(InvalidCategoryException::class);

        CategoryAudience::roles(['socio']);
    }

    public function test_only_a_roles_audience_names_roles(): void
    {
        $this->expectException(InvalidCategoryException::class);

        new CategoryAudience(CategoryAudienceKind::SignedIn, [self::ROLE_A]);
    }

    public function test_it_round_trips_through_an_array(): void
    {
        $audience = CategoryAudience::roles([self::ROLE_A]);

        $this->assertEquals($audience, CategoryAudience::fromArray($audience->toArray()));
        $this->assertEquals(CategoryAudience::everyone(), CategoryAudience::fromArray(['kind' => 'everyone']));
    }

    public function test_an_unreadable_array_is_refused(): void
    {
        $this->expectException(InvalidCategoryException::class);

        CategoryAudience::fromArray(['kind' => 'todo el mundo']);
    }

    public function test_only_everyone_lets_guests_in_and_only_roster_kinds_need_a_publication(): void
    {
        $this->assertTrue(CategoryAudience::everyone()->allowsGuests());
        $this->assertFalse(CategoryAudience::signedIn()->allowsGuests());
        $this->assertTrue(CategoryAudience::roster()->needsSubject());
        $this->assertTrue(CategoryAudience::rosterLeaders()->needsSubject());
        $this->assertFalse(CategoryAudience::associationMembers()->needsSubject());
    }

    /**
     * @return iterable<string, array{CategoryAudience, CategoryAudience, bool}>
     */
    public static function nesting(): iterable
    {
        yield 'anything within everyone' => [CategoryAudience::roster(), CategoryAudience::everyone(), true];
        yield 'everyone within everyone' => [CategoryAudience::everyone(), CategoryAudience::everyone(), true];
        yield 'everyone not within signed in' => [CategoryAudience::everyone(), CategoryAudience::signedIn(), false];
        yield 'members within signed in' => [CategoryAudience::associationMembers(), CategoryAudience::signedIn(), true];
        yield 'signed in not within members' => [CategoryAudience::signedIn(), CategoryAudience::associationMembers(), false];
        yield 'members within members' => [CategoryAudience::associationMembers(), CategoryAudience::associationMembers(), true];
        yield 'fewer roles within more' => [CategoryAudience::roles([self::ROLE_A]), CategoryAudience::roles([self::ROLE_A, self::ROLE_B]), true];
        yield 'more roles not within fewer' => [CategoryAudience::roles([self::ROLE_A, self::ROLE_B]), CategoryAudience::roles([self::ROLE_A]), false];
        yield 'leaders within the roster' => [CategoryAudience::rosterLeaders(), CategoryAudience::roster(), true];
        yield 'the roster not within its leaders' => [CategoryAudience::roster(), CategoryAudience::rosterLeaders(), false];
        // Different groups that may overlap: the subcategory shows to whoever is in both
        yield 'roles within members' => [CategoryAudience::roles([self::ROLE_A]), CategoryAudience::associationMembers(), true];
        yield 'members within roles' => [CategoryAudience::associationMembers(), CategoryAudience::roles([self::ROLE_A]), true];
        yield 'signed in not within roles' => [CategoryAudience::signedIn(), CategoryAudience::roles([self::ROLE_A]), false];
    }

    #[DataProvider('nesting')]
    public function test_a_subcategory_audience_never_reaches_further_than_its_parent(CategoryAudience $child, CategoryAudience $parent, bool $within): void
    {
        $this->assertSame($within, $child->isWithin($parent));
    }
}
