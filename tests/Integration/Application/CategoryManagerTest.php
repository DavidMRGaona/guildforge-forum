<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use Modules\Forum\Application\Contracts\ForumCountersInterface;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Application\Services\DefaultCategoriesInstaller;
use Modules\Forum\Database\Seeders\DefaultCategoriesSeeder;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryAudienceKind;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Exceptions\CategoryNotFoundException;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;

final class CategoryManagerTest extends ModuleTestCase
{
    use BuildsForumContent;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function tearDown(): void
    {
        DefaultCategoriesSeeder::$seedInTests = false;

        parent::tearDown();
    }

    public function test_a_subcategory_is_saved_under_a_top_level_category(): void
    {
        $parent = $this->aCategory('general');
        $child = $this->newCategory('dudas', $parent->id());

        $this->manager()->save($child);

        $this->assertTrue($this->categories()->findOrFail($child->id())->parentId()?->equals($parent->id()));
        $this->assertSame([$child->id()->value], array_map(fn (Category $category): string => $category->id()->value, $this->categories()->childrenOf($parent->id())));
    }

    public function test_subcategories_hold_no_subcategories(): void
    {
        $parent = $this->aCategory('general');
        $child = $this->newCategory('dudas', $parent->id());
        $this->manager()->save($child);

        $this->expectException(InvalidCategoryException::class);

        $this->manager()->save($this->newCategory('nietas', $child->id()));
    }

    public function test_a_category_with_subcategories_never_becomes_one(): void
    {
        $parent = $this->aCategory('general');
        $other = $this->aCategory('otra');
        $this->manager()->save($this->newCategory('dudas', $parent->id()));

        $parent->moveUnder($other->id());

        $this->expectException(InvalidCategoryException::class);

        $this->manager()->save($parent);
    }

    public function test_a_missing_parent_is_reported(): void
    {
        $this->expectException(CategoryNotFoundException::class);

        $this->manager()->save($this->newCategory('dudas', CategoryId::generate()));
    }

    public function test_a_subcategory_is_never_readable_by_more_people_than_its_parent(): void
    {
        $parent = $this->newCategory('socios', view: CategoryAudience::associationMembers());
        $this->manager()->save($parent);

        $this->expectException(InvalidCategoryException::class);

        $this->manager()->save($this->newCategory('abierta', $parent->id(), CategoryAudience::signedIn()));
    }

    public function test_a_parent_is_never_narrowed_below_its_subcategories(): void
    {
        $parent = $this->aCategory('general');
        $this->manager()->save($this->newCategory('dudas', $parent->id(), CategoryAudience::signedIn()));

        $parent->changeAccess(CategoryAudience::associationMembers(), CategoryAudience::associationMembers(), CategoryModerators::none());

        $this->expectException(InvalidCategoryException::class);

        $this->manager()->save($parent);
    }

    public function test_an_empty_category_is_deleted(): void
    {
        $category = $this->aCategory('general');

        $this->manager()->delete($category->id(), null);

        $this->assertNull($this->categories()->find($category->id()));
    }

    public function test_a_category_with_discussions_needs_somewhere_to_move_them(): void
    {
        $category = $this->aCategory('general');
        $this->aDiscussion($category);

        $this->expectException(InvalidCategoryException::class);

        $this->manager()->delete($category->id(), null);
    }

    public function test_its_discussions_are_moved_before_it_is_deleted(): void
    {
        $category = $this->aCategory('general');
        $target = $this->aCategory('archivo');
        [$discussion] = $this->aDiscussion($category);

        $this->manager()->delete($category->id(), $target->id());

        $this->assertNull($this->categories()->find($category->id()));
        $this->assertTrue(app(DiscussionRepositoryInterface::class)->findOrFail($discussion->id())->categoryId()->equals($target->id()));
        $this->assertSame(1, app(ForumCountersInterface::class)->category($target->id())?->discussionCount);
    }

    public function test_discussions_never_move_into_the_category_being_deleted(): void
    {
        $category = $this->aCategory('general');
        $this->aDiscussion($category);

        $this->expectException(InvalidCategoryException::class);

        $this->manager()->delete($category->id(), $category->id());
    }

    public function test_a_category_with_subcategories_is_never_deleted(): void
    {
        $parent = $this->aCategory('general');
        $this->manager()->save($this->newCategory('dudas', $parent->id()));

        $this->expectException(InvalidCategoryException::class);

        $this->manager()->delete($parent->id(), null);
    }

    public function test_a_system_category_is_never_deleted(): void
    {
        $category = Category::system(CategoryId::generate(), SystemCategory::Events, 'Eventos', new CategorySlug('eventos'), 0);
        $this->categories()->save($category);

        $this->expectException(InvalidCategoryException::class);

        $this->manager()->delete($category->id(), null);
    }

    public function test_the_installer_creates_the_starting_categories_once(): void
    {
        app(DefaultCategoriesInstaller::class)->install();
        app(DefaultCategoriesInstaller::class)->install();

        $categories = $this->categories()->all();
        $this->assertSame(['General', 'Partidas por foro', 'Mesas', 'Campañas', 'Eventos'], array_map(fn (Category $category): string => $category->name(), $categories));

        foreach ($categories as $category) {
            $this->assertSame(CategoryAudienceKind::Everyone, $category->viewAudience()->kind);
            $this->assertSame(CategoryAudienceKind::SignedIn, $category->writeAudience()->kind);
        }

        foreach (SystemCategory::cases() as $key) {
            $this->assertTrue($this->categories()->findBySystemKey($key)?->isSystem());
        }
    }

    public function test_a_renamed_system_category_is_not_created_again(): void
    {
        app(DefaultCategoriesInstaller::class)->install();
        $events = $this->categories()->findBySystemKey(SystemCategory::Events);
        $this->assertNotNull($events);
        $events->rename('Quedadas', new CategorySlug('quedadas'), null);
        $this->categories()->save($events);

        app(DefaultCategoriesInstaller::class)->install();

        $this->assertCount(5, $this->categories()->all());
    }

    public function test_the_seeder_installs_them_only_when_allowed_in_tests(): void
    {
        $this->seed(DefaultCategoriesSeeder::class);
        $this->assertSame([], $this->categories()->all());

        DefaultCategoriesSeeder::$seedInTests = true;
        $this->seed(DefaultCategoriesSeeder::class);
        $this->assertCount(5, $this->categories()->all());
    }

    public function test_a_discussion_keeps_its_game_system_tags(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory('general'));
        $repository = app(DiscussionRepositoryInterface::class);

        $repository->replaceTopics($discussion->id(), [new DiscussionTopic('game-system.abc', 'Pathfinder'), new DiscussionTopic('game-system.def', 'Aquelarre')]);
        $repository->replaceTopics($discussion->id(), [new DiscussionTopic('game-system.def', 'Aquelarre')]);

        $topics = $repository->topicsOf($discussion->id());
        $this->assertCount(1, $topics);
        $this->assertSame('Aquelarre', $topics[0]->label);
        $this->assertTrue($topics[0]->isGameSystem());
    }

    private function newCategory(string $slug, ?CategoryId $parentId = null, ?CategoryAudience $view = null): Category
    {
        return new Category(CategoryId::generate(), ucfirst($slug), new CategorySlug($slug), parentId: $parentId, viewAudience: $view);
    }

    private function manager(): CategoryManager
    {
        return app(CategoryManager::class);
    }

    private function categories(): CategoryRepositoryInterface
    {
        return app(CategoryRepositoryInterface::class);
    }
}
