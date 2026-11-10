<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Entities;

use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryAudienceKind;
use Modules\Forum\Domain\Enums\CategoryIcon;
use Modules\Forum\Domain\Enums\RosterScope;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\ValueObjects\CategoryAppearance;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
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

    public function test_a_new_category_is_top_level_readable_by_anyone_and_written_by_accounts(): void
    {
        $category = new Category(CategoryId::generate(), 'General', new CategorySlug('general'));

        $this->assertNull($category->parentId());
        $this->assertSame(CategoryAudienceKind::Everyone, $category->viewAudience()->kind);
        $this->assertSame(CategoryAudienceKind::SignedIn, $category->writeAudience()->kind);
        $this->assertTrue($category->moderators()->isEmpty());
        $this->assertNull($category->subject());
        $this->assertNull($category->systemKey());
        $this->assertFalse($category->isSystem());
        $this->assertEquals(CategoryAppearance::plain(), $category->appearance());
    }

    public function test_guests_never_write(): void
    {
        $this->expectException(InvalidCategoryException::class);

        $this->category()->changeAccess(CategoryAudience::everyone(), CategoryAudience::everyone(), CategoryModerators::none());
    }

    public function test_access_changes(): void
    {
        $category = $this->category();
        $moderators = new CategoryModerators(['0b6c2a5e-1111-4aaa-8aaa-000000000001']);

        $category->changeAccess(CategoryAudience::associationMembers(), CategoryAudience::associationMembers(), $moderators);

        $this->assertSame(CategoryAudienceKind::AssociationMembers, $category->viewAudience()->kind);
        $this->assertSame(CategoryAudienceKind::AssociationMembers, $category->writeAudience()->kind);
        $this->assertSame($moderators, $category->moderators());
    }

    public function test_roster_rules_need_a_publication(): void
    {
        $category = $this->category();

        try {
            $category->changeAccess(CategoryAudience::roster(), CategoryAudience::roster(), CategoryModerators::none());
            $this->fail('A roster audience was accepted without a publication.');
        } catch (InvalidCategoryException) {
        }

        try {
            $category->changeAccess(CategoryAudience::signedIn(), CategoryAudience::signedIn(), new CategoryModerators([], RosterScope::Leaders));
            $this->fail('Roster moderators were accepted without a publication.');
        } catch (InvalidCategoryException) {
        }

        $category->linkTo(new CategorySubject('campaign', 'c-1'));
        $category->changeAccess(CategoryAudience::roster(), CategoryAudience::roster(), new CategoryModerators([], RosterScope::Leaders));

        $this->assertSame(CategoryAudienceKind::Roster, $category->viewAudience()->kind);
    }

    public function test_the_publication_is_not_dropped_while_a_rule_uses_it(): void
    {
        $category = $this->category();
        $category->linkTo(new CategorySubject('campaign', 'c-1'));
        $category->changeAccess(CategoryAudience::roster(), CategoryAudience::roster(), CategoryModerators::none());

        $this->expectException(InvalidCategoryException::class);

        $category->linkTo(null);
    }

    public function test_moving_under_a_parent_and_back_to_the_top(): void
    {
        $category = $this->category();
        $parent = CategoryId::generate();

        $category->moveUnder($parent);
        $this->assertTrue($category->parentId()?->equals($parent));

        $category->moveUnder(null);
        $this->assertNull($category->parentId());
    }

    public function test_a_category_is_never_its_own_parent(): void
    {
        $category = $this->category();

        $this->expectException(InvalidCategoryException::class);

        $category->moveUnder($category->id());
    }

    public function test_restyling(): void
    {
        $category = $this->category();

        $category->restyle(new CategoryAppearance('#123456', CategoryIcon::Megaphone));

        $this->assertSame('#123456', $category->appearance()->color);
        $this->assertSame(CategoryIcon::Megaphone, $category->appearance()->icon);
    }

    public function test_a_system_category_keeps_its_key(): void
    {
        $category = Category::system(CategoryId::generate(), SystemCategory::Campaigns, 'Campañas', CategorySlug::fromName('Campañas'), 2);

        $this->assertTrue($category->isSystem());
        $this->assertSame(SystemCategory::Campaigns, $category->systemKey());
        $this->assertSame('Campañas', $category->name());
        $this->assertSame('campanas', $category->slug()->value);
        $this->assertSame(2, $category->position());
    }

    private function category(): Category
    {
        return new Category(CategoryId::generate(), 'General', new CategorySlug('general'));
    }
}
