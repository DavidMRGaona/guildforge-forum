<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Filament;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Pages\KnowledgeBasePage;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Livewire\Livewire;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Domain\Enums\PostImageType;
use Modules\Forum\Filament\Pages\ForumSettingsPage;
use Modules\Forum\ForumServiceProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The forum settings open with forum:settings.manage (admins hold every permission) and sit in
 * the «Configuración» cluster in the slot kept for the forum.
 */
final class ForumSettingsPageTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    private const string MANAGE = 'forum:settings.manage';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([], [ForumSettingsPage::class]);
    }

    public function test_the_provider_registers_the_page(): void
    {
        $this->assertContains(ForumSettingsPage::class, (new ForumServiceProvider($this->app))->registerFilamentPages());
    }

    public function test_the_page_joins_the_settings_cluster_in_the_forum_slot(): void
    {
        $this->assertSame(SettingsCluster::class, ForumSettingsPage::getCluster());
        $this->assertSame(125, ForumSettingsPage::getNavigationSort());
        $this->assertSame('Foro', ForumSettingsPage::getNavigationLabel());
        $this->assertNull(ForumSettingsPage::getNavigationGroup());
        $this->assertStringStartsWith('/admin/configuracion/', (string) parse_url(ForumSettingsPage::getUrl(), PHP_URL_PATH));
    }

    public function test_only_who_manages_the_forum_settings_opens_it(): void
    {
        $this->actingAs($this->userWith([]));
        $this->assertFalse(ForumSettingsPage::canAccess());
        Livewire::test(ForumSettingsPage::class)->assertForbidden();

        $this->actingAs($this->userWith([self::MANAGE]));
        $this->assertTrue(ForumSettingsPage::canAccess());
        Livewire::test(ForumSettingsPage::class)->assertOk();
    }

    public function test_it_shows_the_defaults_and_saves_new_values(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(ForumSettingsPage::class)
            ->assertSet('data.discussions_per_page', 20)
            ->assertSet('data.posts_per_page', 20)
            ->assertSet('data.posts_per_minute', 6)
            ->assertSet('data.image_types', ['jpeg', 'png', 'webp', 'gif'])
            ->assertSet('data.images_per_post', 10)
            ->set('data.image_types', ['jpeg', 'webp'])
            ->set('data.image_max_dimension', 1600)
            ->set('data.discussions_per_page', 30)
            ->set('data.posts_per_page', 15)
            ->set('data.posts_per_minute', 3)
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(ForumSettingsInterface::class);
        $this->assertSame(30, $settings->discussionsPerPage());
        $this->assertSame(15, $settings->postsPerPage());
        $this->assertSame(3, $settings->postsPerMinute());
        $this->assertSame([PostImageType::Jpeg, PostImageType::Webp], $settings->imageTypes());
        $this->assertSame(1600, $settings->imageMaxDimension());
    }

    public function test_page_sizes_stay_within_bounds(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(ForumSettingsPage::class)
            ->set('data.discussions_per_page', 4)
            ->set('data.posts_per_page', 101)
            ->set('data.posts_per_minute', 0)
            ->set('data.images_per_post', 21)
            ->set('data.image_types', ['jpeg', 'svg'])
            ->call('save')
            ->assertHasErrors(['data.discussions_per_page', 'data.posts_per_page', 'data.posts_per_minute', 'data.images_per_post', 'data.image_types']);
    }

    public function test_the_image_sites_start_with_imgur_and_save_as_host_names(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(ForumSettingsPage::class)
            ->assertSet('data.external_image_hosts', ['imgur.com'])
            ->set('data.external_image_hosts', ['Imgur.com', 'postimg.cc', 'imgur.com'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['imgur.com', 'postimg.cc'], app(ForumSettingsInterface::class)->externalImageHosts());
    }

    public function test_image_sites_that_are_not_host_names_are_refused(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));

        foreach (['https://imgur.com', '*.imgur.com', '127.0.0.1', 'imgur.com/a', 'localhost'] as $entry) {
            Livewire::test(ForumSettingsPage::class)
                ->set('data.external_image_hosts', ['imgur.com', $entry])
                ->call('save')
                ->assertHasErrors(['data.external_image_hosts']);
        }
    }

    public function test_embedded_services_and_their_cookie_categories_are_saved(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(ForumSettingsPage::class)
            ->assertSet('data.embed_services', ['reddit', 'x', 'bluesky', 'instagram', 'tiktok', 'youtube', 'vimeo', 'twitch'])
            ->assertSet('data.embed_cookie_categories.youtube', 'marketing')
            ->set('data.embed_services', ['youtube', 'vimeo'])
            ->set('data.embed_cookie_categories.youtube', 'videos')
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(ForumSettingsInterface::class);
        $this->assertSame(['youtube', 'vimeo'], $settings->embedServices());
        $this->assertSame('videos', $settings->embedCookieCategory('youtube'));
    }

    public function test_unknown_services_and_malformed_categories_are_refused(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(ForumSettingsPage::class)
            ->set('data.embed_services', ['youtube', 'myspace'])
            ->set('data.embed_cookie_categories.x', 'Con espacios')
            ->call('save')
            ->assertHasErrors(['data.embed_services', 'data.embed_cookie_categories.x']);

        Livewire::test(ForumSettingsPage::class)
            ->set('data.embed_cookie_categories.x', "videos\n")
            ->call('save')
            ->assertHasErrors(['data.embed_cookie_categories.x']);
    }

    public function test_saving_is_refused_without_the_permission(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $page = Livewire::test(ForumSettingsPage::class);

        $this->actingAs($this->userWith([]));
        $page->call('save')->assertForbidden();
    }

    public function test_the_header_offers_the_settings_guide_to_whoever_sees_it(): void
    {
        $this->artisanCommand('knowledge:sync')->assertSuccessful()->run();
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(ForumSettingsPage::class)
            ->assertActionVisible('contextualHelp')
            ->assertActionHasUrl('contextualHelp', KnowledgeBasePage::getUrl(['guia' => 'forum.panel.settings']));
    }

    /**
     * A panel user (the editor state gives admin.access) with a role holding these permissions.
     *
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'settings', 'action' => 'manage', 'module' => 'forum'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
