<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Filament;

use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Filament\Actions\RowDetailsAction;
use App\Filament\Pages\KnowledgeBasePage;
use App\Filament\Support\PanelGuideUrl;
use App\Filament\Support\PanelNavigationGroup;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Livewire;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryAudienceKind;
use Modules\Forum\Domain\Enums\CategoryIcon;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Filament\Resources\CategoryResource;
use Modules\Forum\Filament\Resources\CategoryResource\Pages\CreateCategory;
use Modules\Forum\Filament\Resources\CategoryResource\Pages\EditCategory;
use Modules\Forum\Filament\Resources\CategoryResource\Pages\ListCategories;
use Modules\Forum\ForumServiceProvider;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeGameTableSource;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class CategoryResourceTest extends ModuleTestCase
{
    use BuildsForumContent;
    use InteractsWithModulePanel;

    private const string MANAGE = 'forum:categories.manage';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([CategoryResource::class]);
    }

    public function test_the_provider_registers_the_policy(): void
    {
        $this->assertArrayHasKey(CategoryModel::class, (new ForumServiceProvider($this->app))->registerPolicies());
    }

    public function test_it_sits_in_the_communication_group_in_the_forum_slot(): void
    {
        $this->assertSame(PanelNavigationGroup::Communication->getLabel(), CategoryResource::getNavigationGroup());
        $this->assertSame(30, CategoryResource::getNavigationSort());
        $this->assertSame('Categorías del foro', CategoryResource::getNavigationLabel());
        $this->assertNull(CategoryResource::getNavigationParentItem());
    }

    public function test_only_category_managers_open_the_list(): void
    {
        $this->actingAs($this->userWith(['forum:settings.manage']));
        $this->assertFalse(CategoryResource::canViewAny());
        Livewire::test(ListCategories::class)->assertForbidden();

        $this->actingAs($this->userWith([self::MANAGE]));
        $this->assertTrue(CategoryResource::canViewAny());
        Livewire::test(ListCategories::class)->assertOk();
    }

    public function test_it_creates_a_category_with_its_look_and_rules(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $board = RoleModel::query()->create(['name' => 'board', 'display_name' => 'Junta']);

        Livewire::test(CreateCategory::class)
            ->fillForm([
                'name' => 'Junta directiva',
                'slug' => 'junta-directiva',
                'description' => 'Solo para la junta',
                'color' => '#AA3300',
                'icon' => CategoryIcon::Shield->value,
                'view_kind' => CategoryAudienceKind::Roles->value,
                'view_role_ids' => [$board->id],
                'write_kind' => CategoryAudienceKind::Roles->value,
                'write_role_ids' => [$board->id],
                'moderator_role_ids' => [$board->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = app(CategoryRepositoryInterface::class)->findBySlug(new CategorySlug('junta-directiva'));
        $this->assertNotNull($category);
        $this->assertSame('Junta directiva', $category->name());
        $this->assertSame('#aa3300', $category->appearance()->color);
        $this->assertSame(CategoryIcon::Shield, $category->appearance()->icon);
        $this->assertSame(CategoryAudienceKind::Roles, $category->viewAudience()->kind);
        $this->assertSame([strtolower($board->id)], $category->viewAudience()->roleIds);
        $this->assertSame([strtolower($board->id)], $category->moderators()->roleIds);
    }

    public function test_guests_are_never_offered_for_writing_and_roster_rules_only_in_publication_spaces(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Abierta', 'slug' => 'abierta', 'view_kind' => 'everyone', 'write_kind' => 'everyone'])
            ->call('create')
            ->assertHasFormErrors(['write_kind']);

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Plantilla', 'slug' => 'plantilla', 'view_kind' => 'roster', 'write_kind' => 'signed_in'])
            ->call('create')
            ->assertHasFormErrors(['view_kind']);
    }

    public function test_a_roles_rule_needs_its_roles(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Junta', 'slug' => 'junta', 'view_kind' => 'roles', 'view_role_ids' => [], 'write_kind' => 'signed_in'])
            ->call('create')
            ->assertHasFormErrors(['view_role_ids']);
    }

    public function test_a_subcategory_wider_than_its_parent_is_refused_next_to_its_rule(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $parent = $this->aCategory('socios');
        $parent->changeAccess(
            CategoryAudience::associationMembers(),
            CategoryAudience::associationMembers(),
            CategoryModerators::none(),
        );
        app(CategoryRepositoryInterface::class)->save($parent);

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Abierta', 'slug' => 'abierta', 'parent_id' => $parent->id()->value, 'view_kind' => 'everyone', 'write_kind' => 'signed_in'])
            ->call('create')
            ->assertHasFormErrors(['view_kind']);

        $this->assertNull(app(CategoryRepositoryInterface::class)->findBySlug(new CategorySlug('abierta')));
    }

    public function test_only_top_level_categories_are_offered_as_parents(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $parent = $this->aCategory('general');
        $child = new Category(CategoryId::generate(), 'Dudas', new CategorySlug('dudas'), parentId: $parent->id());
        app(CategoryRepositoryInterface::class)->save($child);

        $options = CategoryResource::parentOptions(null);

        $this->assertArrayHasKey($parent->id()->value, $options);
        $this->assertArrayNotHasKey($child->id()->value, $options);
        $this->assertArrayNotHasKey($parent->id()->value, CategoryResource::parentOptions($parent->id()->value));
    }

    public function test_categories_of_a_missing_module_are_listed_marked_and_never_offered_as_parent_or_target(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $general = $this->aCategory('general');
        $tables = Category::system(CategoryId::generate(), SystemCategory::GameTables, 'Mesas', new CategorySlug('mesas'), 1);
        app(CategoryRepositoryInterface::class)->save($tables);
        $child = new Category(CategoryId::generate(), 'Dudas', new CategorySlug('dudas'), parentId: $tables->id());
        app(CategoryRepositoryInterface::class)->save($child);
        $hidden = [$tables->id()->value, $child->id()->value];

        // Listed, so whoever runs the site can still see, edit or delete them, each with a badge and a help text
        Livewire::test(ListCategories::class)
            ->assertCanSeeTableRecords([$general->id()->value, ...$hidden])
            ->assertTableColumnStateSet('availability', 'Sin el módulo que la alimenta', $tables->id()->value)
            ->assertTableColumnStateSet('availability', 'Sin el módulo que la alimenta', $child->id()->value)
            ->assertTableColumnStateNotSet('availability', 'Sin el módulo que la alimenta', $general->id()->value)
            ->assertTableColumnHasDescription('name', __('forum::messages.categories.unavailable_help'), $tables->id()->value)
            ->assertTableColumnHasDescription('name', __('forum::messages.categories.unavailable_help'), $child->id()->value);
        $this->get(CategoryResource::getUrl('edit', ['record' => $tables->id()->value]))->assertOk();

        // Never where new discussions or subcategories would go
        $this->assertSame([$general->id()->value], array_keys(CategoryResource::parentOptions(null)));
        $this->assertSame([], CategoryResource::moveTargets($general->id()->value));
        $this->assertSame([$general->id()->value], array_keys(CategoryResource::moveTargets($child->id()->value)));

        // A hidden subcategory keeps its hidden parent when it is saved
        Livewire::test(EditCategory::class, ['record' => $child->id()->value])
            ->fillForm(['name' => 'Dudas de reglas'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue(app(CategoryRepositoryInterface::class)->findOrFail($child->id())->parentId()?->equals($tables->id()));

        app(PublicationSourceRegistryInterface::class)->register(FakeGameTableSource::class, 'example');
        $this->app->forgetScopedInstances();

        Livewire::test(ListCategories::class)->assertTableColumnStateNotSet('availability', 'Sin el módulo que la alimenta', $tables->id()->value);
        $this->assertArrayHasKey($tables->id()->value, CategoryResource::parentOptions(null));
    }

    public function test_slugs_are_unique_and_kebab_case(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $this->aCategory('general');

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'General', 'slug' => 'general', 'view_kind' => 'everyone', 'write_kind' => 'signed_in'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Otra', 'slug' => 'Con Espacios', 'view_kind' => 'everyone', 'write_kind' => 'signed_in'])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_editing_keeps_the_system_key(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $events = Category::system(CategoryId::generate(), SystemCategory::Events, 'Eventos', new CategorySlug('eventos'), 0);
        app(CategoryRepositoryInterface::class)->save($events);

        Livewire::test(EditCategory::class, ['record' => $events->id()->value])
            ->assertSchemaStateSet(['name' => 'Eventos', 'view_kind' => 'everyone', 'write_kind' => 'signed_in'])
            ->fillForm(['name' => 'Quedadas', 'slug' => 'quedadas'])
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = app(CategoryRepositoryInterface::class)->findOrFail($events->id());
        $this->assertSame('Quedadas', $saved->name());
        $this->assertSame(SystemCategory::Events, $saved->systemKey());
    }

    public function test_an_empty_category_is_deleted_with_the_confirmation_box(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $category = $this->aCategory('general');
        $record = CategoryModel::query()->findOrFail($category->id()->value);

        Livewire::test(ListCategories::class)
            ->callTableAction('delete', $record, ['confirmation' => false])
            ->assertHasTableActionErrors(['confirmation']);

        Livewire::test(ListCategories::class)
            ->callTableAction('delete', $record, ['confirmation' => true])
            ->assertHasNoTableActionErrors();

        $this->assertNull(CategoryModel::query()->find($category->id()->value));
    }

    public function test_a_category_with_discussions_moves_them_before_it_is_deleted(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $category = $this->aCategory('general');
        $target = $this->aCategory('archivo');
        [$discussion] = $this->aDiscussion($category);
        $record = CategoryModel::query()->findOrFail($category->id()->value);

        Livewire::test(ListCategories::class)
            ->callTableAction('delete', $record, ['move_to' => null, 'confirmation' => true])
            ->assertHasTableActionErrors(['move_to']);

        Livewire::test(ListCategories::class)
            ->callTableAction('delete', $record, ['move_to' => $target->id()->value, 'confirmation' => true])
            ->assertHasNoTableActionErrors();

        $this->assertNull(CategoryModel::query()->find($category->id()->value));
        $this->assertDatabaseHas('forum_discussions', ['id' => $discussion->id()->value, 'category_id' => $target->id()->value]);
    }

    public function test_system_categories_and_categories_with_subcategories_offer_no_delete(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $events = Category::system(CategoryId::generate(), SystemCategory::Events, 'Eventos', new CategorySlug('eventos'), 0);
        app(CategoryRepositoryInterface::class)->save($events);
        $parent = $this->aCategory('general');
        app(CategoryRepositoryInterface::class)->save(new Category(CategoryId::generate(), 'Dudas', new CategorySlug('dudas'), parentId: $parent->id()));

        $action = $this->table()->getAction('delete');
        $this->assertNotNull($action);

        $this->assertTrue($action->record(CategoryModel::query()->findOrFail($events->id()->value))->isHidden());
        $this->assertTrue($action->record(CategoryModel::query()->findOrFail($parent->id()->value))->isHidden());
        $this->assertFalse(CategoryResource::canDelete(CategoryModel::query()->findOrFail($events->id()->value)));
    }

    public function test_the_list_is_reorderable_by_dragging(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $first = $this->aCategory('primera', 0);
        $second = $this->aCategory('segunda', 1);

        $this->assertSame('position', $this->table()->getReorderColumn());

        Livewire::test(ListCategories::class)->call('reorderTable', [$second->id()->value, $first->id()->value]);

        $this->assertSame(['segunda', 'primera'], CategoryModel::query()->orderBy('position')->pluck('slug')->all());
    }

    public function test_the_row_actions_sit_in_one_menu_that_starts_with_the_details(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $actions = $this->table()->getRecordActions();

        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ActionGroup::class, $actions[0]);
        $this->assertSame(RowDetailsAction::NAME, array_key_first($actions[0]->getFlatActions()));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function columnBreakpoints(): iterable
    {
        yield 'name' => ['name', null];
        yield 'parent' => ['parent.name', 'md'];
        yield 'discussions' => ['discussion_count', 'md'];
        yield 'readers' => ['view_rule', 'xl'];
        yield 'system' => ['system_key', 'xl'];
        yield 'availability' => ['availability', '2xl'];
    }

    #[DataProvider('columnBreakpoints')]
    public function test_secondary_columns_appear_from_the_width_where_they_fit(string $column, ?string $breakpoint): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));

        $this->assertSame($breakpoint, $this->table()->getColumn($column)?->getVisibleFrom());
    }

    public function test_the_name_wraps_instead_of_widening_the_table(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $column = $this->table()->getColumn('name');

        $this->assertInstanceOf(TextColumn::class, $column);
        $this->assertTrue($column->canWrap());
    }

    private function table(): Table
    {
        $instance = Livewire::test(ListCategories::class)->instance();
        $this->assertInstanceOf(HasTable::class, $instance);

        return $instance->getTable();
    }

    public function test_the_list_the_form_and_the_edit_page_offer_the_categories_guide(): void
    {
        $this->artisanCommand('knowledge:sync')->assertSuccessful()->run();
        $this->actingAs($this->userWith(['forum:categories.manage']));
        $url = KnowledgeBasePage::getUrl(['guia' => 'forum.panel.categories']);

        Livewire::test(ListCategories::class)->assertActionVisible('contextualHelp')->assertActionHasUrl('contextualHelp', $url);
        Livewire::test(CreateCategory::class)->assertActionVisible('contextualHelp')->assertActionHasUrl('contextualHelp', $url);

        $category = $this->aCategory('general');
        Livewire::test(EditCategory::class, ['record' => $category->id()->value])
            ->assertActionVisible('contextualHelp')
            ->assertActionHasUrl('contextualHelp', $url);
    }

    public function test_whoever_does_not_see_the_guide_gets_no_help_button(): void
    {
        $this->artisanCommand('knowledge:sync')->assertSuccessful()->run();
        $this->actingAs($this->userWith(['forum:settings.manage']));

        $this->assertNull(PanelGuideUrl::for('forum.panel.categories'));
    }

    /**
     * A panel user (the editor state gives admin.access) with a role holding these permissions.
     *
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester-'.strtolower(uniqid()), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'categories', 'action' => 'manage', 'module' => 'forum'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
