<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature;

use App\Application\Knowledge\Services\KnowledgeCapabilityRegistryInterface;
use App\Application\Mail\Services\MailCategoryRegistryInterface;
use App\Domain\Modules\Events\ModuleUpdated;
use App\Domain\Navigation\Enums\MenuVisibility;
use App\Infrastructure\Navigation\Persistence\Eloquent\Models\MenuItemModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumMenuLink;
use Modules\Forum\Application\Services\ForumVisibility;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Database\Seeders\ForumMenuLinkSeeder;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\ForumServiceProvider;
use Modules\Forum\Infrastructure\Realtime\AccountRealtimeChannel;
use Modules\Forum\Infrastructure\Realtime\ConversationRealtimeChannel;
use Modules\Forum\Notifications\ForumMailCategories;
use Modules\Forum\Notifications\UserSuspendedNotification;
use Modules\Forum\Tests\Support\BuildsConversations;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * «Acceso al foro»: open to the whole site, or a preview only whoever holds forum:forum.preview sees
 * (admins hold every permission). For everyone else the forum does not exist: its pages answer 404,
 * its menu link and slots are gone, its reading rules see nothing, and it notifies nobody.
 */
final class ForumPreviewAccessTest extends ModuleTestCase
{
    use BuildsConversations;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string PREVIEW = 'forum:forum.preview';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $public;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->public = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        config(['modules.settings.forum.access' => 'preview']);
    }

    public function test_in_preview_the_forum_answers_404_to_whoever_lacks_the_permission(): void
    {
        $this->get('/foro')->assertNotFound();
        $this->getJson('/foro/fichas/event/x')->assertNotFound();

        $reader = $this->anAccount();
        $this->actingAs($reader)->get('/foro')->assertNotFound();
        $this->actingAs($reader)->getJson('/foro/novedades')->assertNotFound();
        $this->actingAs($reader)->get('/foro/conversaciones')->assertNotFound();
        // A moderator of the whole forum is no exception
        $this->actingAs($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM))->get('/foro')->assertNotFound();

        $this->actingAs($this->anAccount(self::PREVIEW))->get('/foro')->assertOk();
        $this->actingAs(UserModel::factory()->admin()->create())->get('/foro')->assertOk();
    }

    public function test_an_open_forum_answers_everyone(): void
    {
        config(['modules.settings.forum.access' => 'open']);

        $this->get('/foro')->assertOk();
        $this->actingAs($this->anAccount())->get('/foro')->assertOk();
    }

    public function test_an_unreadable_access_setting_keeps_the_forum_in_preview(): void
    {
        config(['modules.settings.forum.access' => 'everyone']);
        $this->get('/foro')->assertNotFound();

        config(['modules.settings.forum.access' => ['open']]);
        $this->get('/foro')->assertNotFound();
    }

    public function test_the_reading_rules_see_nothing_without_the_permission(): void
    {
        $policy = app(CategoryAccessPolicy::class);
        $reader = $this->anAccount();
        $moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);

        $this->assertFalse($policy->canView(null, $this->public));
        $this->assertFalse($policy->canView($reader, $this->public));
        $this->assertFalse($policy->canWrite($reader, $this->public));
        $this->assertFalse($policy->canView($moderator, $this->public));
        $this->assertFalse($policy->canWrite($moderator, $this->public));

        $previewer = $this->anAccount(self::PREVIEW);
        $this->assertTrue($policy->canView($previewer, $this->public));
        $this->assertTrue($policy->canWrite($previewer, $this->public));

        config(['modules.settings.forum.access' => 'open']);
        $this->assertTrue(app(CategoryAccessPolicy::class)->canView(null, $this->public));
    }

    public function test_real_time_channels_refuse_whoever_cannot_see_the_forum(): void
    {
        $reader = $this->anAccount();
        $previewer = $this->anAccount(self::PREVIEW);
        $conversation = $this->aConversation($previewer, $reader);
        $account = app(AccountRealtimeChannel::class);
        $conversations = app(ConversationRealtimeChannel::class);

        $this->assertFalse($account->authorize($reader, ['account' => strtolower((string) $reader->id)]));
        $this->assertFalse($conversations->authorize($reader, ['conversation' => $conversation->id()->value]));

        $this->assertTrue($account->authorize($previewer, ['account' => strtolower((string) $previewer->id)]));
        $this->assertTrue($conversations->authorize($previewer, ['conversation' => $conversation->id()->value]));
    }

    public function test_notices_are_never_sent_to_whoever_cannot_see_the_forum(): void
    {
        $moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);
        $suspended = $this->anAccount();
        $suspension = app(SuspensionService::class)->suspend($moderator, (string) $suspended->id, 'Spam', null);
        $notification = new UserSuspendedNotification($suspension->id()->value);

        $this->assertFalse($notification->shouldSend($suspended, 'mail'));
        $this->assertFalse($notification->shouldSend($suspended, 'site'));

        config(['modules.settings.forum.access' => 'open']);
        $this->assertTrue($notification->shouldSend($suspended, 'mail'));
    }

    public function test_the_forum_mail_categories_are_listed_only_to_whoever_sees_the_forum(): void
    {
        $reader = $this->anAccount();
        $previewer = $this->anAccount(self::PREVIEW);
        $registry = app(MailCategoryRegistryInterface::class);

        foreach (ForumMailCategories::all() as $category) {
            $registered = $registry->find($category->key);
            $this->assertNotNull($registered, $category->key);
            $this->assertNotNull($registered->audience, $category->key);
            $this->assertFalse(($registered->audience)($reader), $category->key);
            $this->assertTrue(($registered->audience)($previewer), $category->key);
        }
    }

    public function test_pages_learn_whether_to_draw_the_forum(): void
    {
        $this->actingAs($this->anAccount())->get('/')->assertInertia(fn (Assert $page) => $page->where('forumVisible', false));
        $this->actingAs($this->anAccount(self::PREVIEW))->get('/')->assertInertia(fn (Assert $page) => $page->where('forumVisible', true));
    }

    public function test_the_profile_tabs_hide_with_the_forum(): void
    {
        $tabs = array_filter(
            (new ForumServiceProvider($this->app))->registerSlots(),
            static fn ($slot): bool => $slot->slot === 'profile-sections',
        );

        $this->assertNotEmpty($tabs);
        foreach ($tabs as $tab) {
            $this->assertSame('forumVisible', $tab->profileTab['visibleKey'] ?? null, $tab->component);
            $this->assertContains('forumVisible', $tab->dataKeys, $tab->component);
        }
    }

    public function test_the_help_guides_ask_whether_the_reader_sees_the_forum(): void
    {
        $capabilities = app(KnowledgeCapabilityRegistryInterface::class);

        $this->assertContains('forum.visible', $capabilities->keys());
        $this->assertFalse($capabilities->evaluate('forum.visible', $this->anAccount()));
        $this->assertTrue($capabilities->evaluate('forum.visible', $this->anAccount(self::PREVIEW)));
    }

    public function test_a_new_menu_link_is_only_for_the_preview_while_the_forum_is_in_preview(): void
    {
        // Enabling the module at the start of the test already added one, with the forum open
        MenuItemModel::query()->where('route', ForumMenuLinkSeeder::ROUTE)->delete();

        (new ForumMenuLinkSeeder)->run();

        $link = $this->menuLink();
        $this->assertSame(MenuVisibility::Permission->value, $link->visibility->value);
        $this->assertSame([self::PREVIEW], $link->permissions);
    }

    public function test_the_menu_link_follows_the_access_and_gets_back_what_it_had(): void
    {
        config(['modules.settings.forum.access' => 'open']);
        (new ForumMenuLinkSeeder)->run();
        MenuItemModel::query()->where('route', ForumMenuLinkSeeder::ROUTE)->update(['visibility' => MenuVisibility::Authenticated->value]);

        config(['modules.settings.forum.access' => 'preview']);
        app(ForumMenuLink::class)->applyAccess();
        $this->assertSame(MenuVisibility::Permission->value, $this->menuLink()->visibility->value);
        $this->assertSame([self::PREVIEW], $this->menuLink()->permissions);

        // Applying it again changes nothing, and does not forget what it had
        app(ForumMenuLink::class)->applyAccess();

        config(['modules.settings.forum.access' => 'open']);
        app(ForumMenuLink::class)->applyAccess();
        $this->assertSame(MenuVisibility::Authenticated->value, $this->menuLink()->visibility->value);
        $this->assertSame([], $this->menuLink()->permissions);
    }

    public function test_updating_the_module_puts_an_existing_link_in_preview(): void
    {
        config(['modules.settings.forum.access' => 'open']);
        (new ForumMenuLinkSeeder)->run();
        config(['modules.settings.forum.access' => 'preview']);

        event(new ModuleUpdated('forum', '1.0.0', '1.1.0', $this->moduleContext()->modulePath('forum')));

        $this->assertSame(MenuVisibility::Permission->value, $this->menuLink()->visibility->value);
    }

    public function test_whoever_sees_the_forum_is_asked_through_one_rule(): void
    {
        $visibility = app(ForumVisibility::class);

        $this->assertFalse($visibility->isOpen());
        $this->assertFalse($visibility->isVisibleTo(null));
        $this->assertFalse($visibility->isVisibleTo($this->anAccount()));
        $this->assertTrue($visibility->isVisibleTo($this->anAccount(self::PREVIEW)));

        config(['modules.settings.forum.access' => 'open']);
        $this->assertTrue($visibility->isOpen());
        $this->assertTrue($visibility->isVisibleTo(null));
    }

    private function menuLink(): MenuItemModel
    {
        return MenuItemModel::query()->where('route', ForumMenuLinkSeeder::ROUTE)->sole();
    }
}
