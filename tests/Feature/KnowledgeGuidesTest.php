<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Knowledge\Services\KnowledgeQueryServiceInterface;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Domain\Knowledge\Enums\GuideAudience;
use App\Filament\Support\PanelGuideUrl;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Mockery\MockInterface;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CharacterAccessMode;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeCampaignSource;
use Modules\Forum\Tests\Support\FakeGameTableSource;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The forum's help guides: they pass the knowledge base lint, the buttons point to guides that
 * exist, and each guide shows only to whoever the forum (and the modules it builds on) lets use
 * what it describes.
 */
final class KnowledgeGuidesTest extends ModuleTestCase
{
    use BuildsForumContent;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    /** Guides anyone with an account reads, whatever modules are installed */
    private const array EVERYONE = [
        'forum.site.overview',
        'forum.site.writing',
        'forum.site.notices',
        'forum.site.play-by-post',
        'forum.site.dice',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisanCommand('knowledge:sync')->assertSuccessful()->run();
    }

    protected function tearDown(): void
    {
        app(PublicationSourceRegistryInterface::class)->unregisterModule('example');
        FakeGameTableSource::reset();

        parent::tearDown();
    }

    public function test_guides_are_valid(): void
    {
        $this->assertModuleKnowledgeGuidesAreValid('forum');
    }

    public function test_every_guide_id_carries_the_module_prefix_and_is_unique(): void
    {
        $ids = $this->guideIdsInFiles();

        $this->assertNotSame([], $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        foreach ($ids as $id) {
            $this->assertStringStartsWith('forum.', $id);
        }
    }

    public function test_every_help_button_points_to_a_guide_of_the_module(): void
    {
        $known = $this->guideIdsInFiles();
        $used = [];

        $sources = [...glob(__DIR__.'/../../src/Filament/{Pages,Resources/*,Resources/*/Pages}/*.php', GLOB_BRACE) ?: [], ...$this->vueFiles()];
        foreach ($sources as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all('/knowledgeGuideAction\(\s*[\'"]([a-z0-9.-]+)[\'"]/', $code, $php);
            preg_match_all('/<HelpLink[^>]*\sguide="([a-z0-9.-]+)"/', $code, $vue);
            foreach ([...$php[1], ...$vue[1]] as $id) {
                $used[$id] = $file;
            }
        }

        // The panel pages, the editor and the forum's front page each carry one
        $this->assertGreaterThanOrEqual(5, count($used));
        foreach (array_keys($used) as $id) {
            $this->assertContains($id, $known, "{$used[$id]} points to «{$id}», which has no guide");
        }
    }

    public function test_the_help_button_of_each_panel_page_shows_for_whoever_sees_its_guide(): void
    {
        $this->actingAs($this->anAccount('forum:categories.manage', 'forum:forum.moderate', 'forum:settings.manage'));

        foreach (['forum.panel.categories', 'forum.panel.moderation', 'forum.panel.settings'] as $id) {
            $this->assertNotNull(PanelGuideUrl::for($id), "{$id} is not offered");
        }
    }

    public function test_a_signed_in_person_reads_the_general_guides_and_nothing_that_needs_a_module_or_a_permission(): void
    {
        $visible = $this->visibleSite($this->anAccount());

        foreach (self::EVERYONE as $id) {
            $this->assertContains($id, $visible);
        }
        foreach (['forum.site.table-topics', 'forum.site.campaign-spaces', 'forum.site.moderating', 'forum.site.images', 'forum.site.external-images'] as $id) {
            $this->assertNotContains($id, $visible);
        }
    }

    public function test_table_topics_and_campaign_spaces_show_only_while_game_tables_and_campaigns_are_offered(): void
    {
        $person = $this->anAccount();
        $registry = app(PublicationSourceRegistryInterface::class);

        $this->assertNotContains('forum.site.table-topics', $this->visibleSite($person));
        $this->assertNotContains('forum.site.campaign-spaces', $this->visibleSite($person));

        $registry->register(FakeGameTableSource::class, 'example');
        $visible = $this->visibleSite($person);
        $this->assertContains('forum.site.table-topics', $visible);
        $this->assertNotContains('forum.site.campaign-spaces', $visible);

        $registry->register(FakeCampaignSource::class, 'example');
        $visible = $this->visibleSite($person);
        $this->assertContains('forum.site.table-topics', $visible);
        $this->assertContains('forum.site.campaign-spaces', $visible);

        // The module is disabled: its guides go with it, deleting nothing
        $registry->unregisterModule('example');
        $visible = $this->visibleSite($person);
        $this->assertNotContains('forum.site.table-topics', $visible);
        $this->assertNotContains('forum.site.campaign-spaces', $visible);
    }

    public function test_a_registry_that_fails_hides_the_guides_that_depend_on_it(): void
    {
        $person = $this->anAccount();
        app(PublicationSourceRegistryInterface::class)->register(FakeGameTableSource::class, 'example');
        $this->assertContains('forum.site.table-topics', $this->visibleSite($person));

        $this->mock(PublicationSourceRegistryInterface::class, static function (MockInterface $registry): void {
            $registry->shouldIgnoreMissing();
            $registry->shouldReceive('source')->andThrow(new RuntimeException('Registry unavailable'));
        });

        $this->assertNotContains('forum.site.table-topics', $this->visibleSite($person));
    }

    public function test_moderation_guides_show_to_whoever_moderates(): void
    {
        $plain = $this->anAccount();
        $moderator = $this->anAccount('forum:forum.moderate');

        $this->assertNotContains('forum.site.moderating', $this->visibleSite($plain));
        $this->assertNotContains('forum.panel.moderation', $this->visiblePanel($plain));

        $this->assertContains('forum.site.moderating', $this->visibleSite($moderator));
        $this->assertContains('forum.panel.moderation', $this->visiblePanel($moderator));
    }

    public function test_whoever_moderates_only_a_category_reads_the_site_guide_but_not_the_panel_one(): void
    {
        $role = RoleModel::query()->create(['name' => 'tablemasters', 'display_name' => 'Tablemasters']);
        $category = new Category(
            CategoryId::generate(),
            'Rol',
            new CategorySlug('rol'),
            null,
            1,
            viewAudience: CategoryAudience::everyone(),
            writeAudience: CategoryAudience::signedIn(),
            moderators: new CategoryModerators([$role->id]),
        );
        app(CategoryRepositoryInterface::class)->save($category);
        $keeper = UserModel::factory()->create();
        $keeper->roles()->attach($role->id);
        $this->forgetAuthorization($keeper);

        $this->assertContains('forum.site.moderating', $this->visibleSite($keeper));
        $this->assertNotContains('forum.panel.moderation', $this->visiblePanel($keeper));
    }

    public function test_a_suspended_moderator_does_not_see_the_moderation_guides(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        $this->assertContains('forum.site.moderating', $this->visibleSite($moderator));

        app(SuspensionService::class)->suspend(UserModel::factory()->admin()->create(), (string) $moderator->id, 'Prueba', null);
        $this->app->forgetScopedInstances();

        $this->assertNotContains('forum.site.moderating', $this->visibleSite($moderator));
        $this->assertNotContains('forum.panel.moderation', $this->visiblePanel($moderator));
    }

    public function test_image_guides_follow_their_permissions(): void
    {
        $this->assertNotContains('forum.site.images', $this->visibleSite($this->anAccount()));
        $this->assertContains('forum.site.images', $this->visibleSite($this->anAccount('forum:posts.upload_images')));

        $this->assertNotContains('forum.site.external-images', $this->visibleSite($this->anAccount('forum:posts.upload_images')));
        $this->assertContains('forum.site.external-images', $this->visibleSite($this->anAccount('forum:posts.external_images')));
    }

    public function test_panel_guides_follow_their_permissions(): void
    {
        $plain = $this->visiblePanel($this->anAccount());
        $this->assertSame([], array_values(array_filter($plain, static fn (string $id): bool => str_starts_with($id, 'forum.'))));

        $this->assertContains('forum.panel.categories', $this->visiblePanel($this->anAccount('forum:categories.manage')));
        $this->assertNotContains('forum.panel.settings', $this->visiblePanel($this->anAccount('forum:categories.manage')));
        $this->assertContains('forum.panel.settings', $this->visiblePanel($this->anAccount('forum:settings.manage')));
    }

    public function test_the_characters_guide_follows_the_forum_setting_and_its_permission(): void
    {
        $person = $this->anAccount();
        $holder = $this->anAccount('forum:characters.use');

        $this->setCharacterAccess(CharacterAccessMode::Everyone);
        $this->assertContains('forum.site.characters', $this->visibleSite($person));

        $this->setCharacterAccess(CharacterAccessMode::Nobody);
        $this->assertNotContains('forum.site.characters', $this->visibleSite($person));
        $this->assertNotContains('forum.site.characters', $this->visibleSite($holder));

        $this->setCharacterAccess(CharacterAccessMode::Permission);
        $this->assertNotContains('forum.site.characters', $this->visibleSite($person));
        $this->assertContains('forum.site.characters', $this->visibleSite($holder));
    }

    public function test_the_embeds_guide_shows_only_while_some_service_is_enabled(): void
    {
        $person = $this->anAccount();

        $this->setEmbedServices(['youtube']);
        $this->assertContains('forum.site.embeds', $this->visibleSite($person));

        $this->setEmbedServices([]);
        $this->assertNotContains('forum.site.embeds', $this->visibleSite($person));
    }

    /**
     * @return list<string>
     */
    private function visibleSite(UserModel $user): array
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(KnowledgeQueryServiceInterface::class)->visibleIds($user, GuideAudience::Site);
    }

    /**
     * @return list<string>
     */
    private function visiblePanel(UserModel $user): array
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(KnowledgeQueryServiceInterface::class)->visibleIds($user, GuideAudience::Panel);
    }

    private function forgetAuthorization(UserModel $user): void
    {
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($user);
    }

    private function setCharacterAccess(CharacterAccessMode $mode): void
    {
        $this->forumSetting('characters_access', $mode->value);
    }

    /**
     * @param  list<string>  $services
     */
    private function setEmbedServices(array $services): void
    {
        $this->forumSetting('embed_services', $services);
    }

    private function forumSetting(string $key, mixed $value): void
    {
        config(["modules.settings.forum.{$key}" => $value]);
    }

    /**
     * @return list<string>
     */
    private function guideIdsInFiles(): array
    {
        $ids = [];
        foreach (glob(__DIR__.'/../../resources/knowledge/es/{site,panel}/*.md', GLOB_BRACE) ?: [] as $file) {
            if (preg_match('/^id:\s*(\S+)\s*$/m', (string) file_get_contents($file), $match) === 1) {
                $ids[] = $match[1];
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function vueFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../resources/js', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
