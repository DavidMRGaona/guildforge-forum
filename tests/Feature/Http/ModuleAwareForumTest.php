<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Application\SiteNotifications\Services\SiteNotificationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Search\Index\NullSearchIndexWriter;
use App\Infrastructure\Search\Index\SearchIndexWriterInterface;
use ArrayObject;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\FollowedThings;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Notifications\ReplyPostedNotification;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeCampaignSource;
use Modules\Forum\Tests\Support\FakeGameTableSource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * A site whose forum already has «Mesas» and «Campañas» (and a campaign's space) but no module
 * offering game tables or campaigns: all of it is hidden from everyone but the forum's moderators,
 * with nothing deleted and no data migrated, and it shows again once the sources are back (forum#49).
 */
final class ModuleAwareForumTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private Category $tables;

    private Category $campaigns;

    private Category $space;

    private Discussion $tableDiscussion;

    private Discussion $spaceDiscussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        // Only the stored search documents, never a real engine
        $this->app->instance(SearchIndexWriterInterface::class, new NullSearchIndexWriter);
        $this->general = $this->aCategory('general');
        $this->tables = $this->system(SystemCategory::GameTables, 'Mesas', 1);
        $this->campaigns = $this->system(SystemCategory::Campaigns, 'Campañas', 2);
        $this->system(SystemCategory::Events, 'Eventos', 3);
        $this->space = new Category(
            CategoryId::generate(),
            'La tumba',
            new CategorySlug('la-tumba'),
            position: 4,
            parentId: $this->campaigns->id(),
            viewAudience: CategoryAudience::everyone(),
            writeAudience: CategoryAudience::signedIn(),
            subject: new CategorySubject(FakeCampaignSource::TYPE, 'c-1'),
        );
        app(CategoryRepositoryInterface::class)->save($this->space);

        $this->aDiscussion($this->general, '2026-11-10 10:00:00', title: 'Hola');
        [$this->tableDiscussion] = $this->aDiscussion($this->tables, '2026-11-10 11:00:00', title: 'Mesa del sábado');
        [$this->spaceDiscussion] = $this->aDiscussion($this->space, '2026-11-10 12:00:00', title: 'Diario de campaña');
    }

    public function test_readers_never_see_tables_campaigns_or_their_spaces(): void
    {
        foreach ([null, $this->anAccount()] as $reader) {
            if ($reader instanceof UserModel) {
                $this->actingAs($reader);
            }

            $this->get('/foro')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('categories', fn ($cards): bool => array_column($cards->all(), 'name') === ['General', 'Eventos'])
                    ->where('discussions.items', fn ($items): bool => array_column($items->all(), 'title') === ['Hola'])
                    ->where('search.categories', fn ($options): bool => array_column($options->all(), 'name') === ['General', 'Eventos']));

            $this->get('/foro/c/mesas')->assertNotFound();
            $this->get('/foro/c/campanas')->assertNotFound();
            $this->get('/foro/c/la-tumba')->assertNotFound();
            $this->get($this->url($this->tableDiscussion))->assertNotFound();
            $this->get($this->url($this->spaceDiscussion))->assertNotFound();
        }
    }

    public function test_the_forum_moderators_still_see_it_marked_as_hidden(): void
    {
        $this->actingAs($this->forumModerator());

        $this->get('/foro/c/mesas')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('category.unavailable', true));
        $this->get('/foro/c/general')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('category.unavailable', false));
        $this->get($this->url($this->spaceDiscussion))->assertOk();
    }

    public function test_nothing_new_goes_into_what_is_hidden(): void
    {
        $moderator = $this->forumModerator();

        $this->actingAs($moderator)
            ->get('/foro/nuevo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories', fn ($options): bool => array_column($options->all(), 'name') === ['General']));

        $this->actingAs($moderator)
            ->postJson('/foro/temas', [
                'category' => $this->space->id()->value,
                'title' => 'Otro tema',
                'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texto']]]]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        [$discussion] = $this->aDiscussion($this->general, '2026-11-10 13:00:00', title: 'Para mover');
        $this->expectException(ModerationRefusedException::class);
        app(ForumModeration::class)->move($moderator, $discussion->id(), $this->space->id());
    }

    public function test_enabling_and_disabling_game_tables_shows_and_hides_what_was_there(): void
    {
        $registry = app(PublicationSourceRegistryInterface::class);

        $registry->register(FakeGameTableSource::class, 'example');
        $registry->register(FakeCampaignSource::class, 'example');
        $this->asANewProcess();

        $this->get('/foro')->assertInertia(fn (Assert $page) => $page
            ->where('categories', fn ($cards): bool => array_column($cards->all(), 'name') === ['General', 'Mesas', 'Campañas', 'Eventos']));
        $this->get('/foro/c/la-tumba')->assertOk();
        $this->get($this->url($this->tableDiscussion))->assertOk();

        $registry->unregisterModule('example');
        $this->asANewProcess();

        $this->get('/foro/c/mesas')->assertNotFound();
        $this->get($this->url($this->spaceDiscussion))->assertNotFound();
        $this->assertCount(5, app(CategoryRepositoryInterface::class)->all(), 'Hiding deletes nothing.');
    }

    public function test_who_follows_something_hidden_hears_nothing_about_it(): void
    {
        $follower = $this->anAccount();
        $mailed = [];
        Event::listen(NotificationSent::class, static function (NotificationSent $event) use (&$mailed, $follower): void {
            if (strtolower((string) $event->notifiable->getKey()) === strtolower($follower->id)) {
                $mailed[] = $event->notification::class;
            }
        });
        app(FollowsInterface::class)->setDiscussionLevel(strtolower($follower->id), $this->tableDiscussion->id(), FollowLevel::Following, CarbonImmutable::now());
        $this->assertSame([], app(FollowedThings::class)->discussions($follower), 'Hidden discussions are not offered as followed.');

        $moderator = $this->forumModerator();
        app(ForumWriter::class)->reply($this->tableDiscussion->id(), PostId::generate(), strtolower($moderator->id), self::aBody('Cerramos la mesa'), CarbonImmutable::now(), needsApproval: false);

        $this->assertSame([], $mailed);
        $this->assertSame(0, app(SiteNotificationServiceInterface::class)->total($follower));
    }

    public function test_a_notice_queued_before_the_module_went_away_is_not_delivered(): void
    {
        $registry = app(PublicationSourceRegistryInterface::class);
        $registry->register(FakeGameTableSource::class, 'example');
        $registry->register(FakeCampaignSource::class, 'example');
        $this->asANewProcess();

        $follower = $this->anAccount();
        /** @var ArrayObject<int, class-string> $mailed */
        $mailed = new ArrayObject;
        Event::listen(NotificationSent::class, static function (NotificationSent $event) use ($mailed): void {
            if ($event->channel === 'mail') {
                $mailed->append($event->notification::class);
            }
        });
        app(FollowsInterface::class)->setDiscussionLevel(strtolower($follower->id), $this->tableDiscussion->id(), FollowLevel::Following, CarbonImmutable::now());

        // The reply's notices wait in the queue, as the worker gets them: decided only when delivered
        Queue::fake();
        $reply = app(ForumWriter::class)->reply($this->tableDiscussion->id(), PostId::generate(), strtolower($this->forumModerator()->id), self::aBody('Cambiamos de hora'), CarbonImmutable::now(), needsApproval: false);
        $queued = serialize(new ReplyPostedNotification($reply->id()->value));

        $follower->notifyNow(unserialize($queued));
        $this->assertCount(1, $mailed, 'While the module is there, the notice is delivered.');

        // The module is disabled before the worker delivers another copy
        $registry->unregisterModule('example');
        $this->asANewProcess();

        $notice = unserialize($queued);
        $this->assertInstanceOf(ReplyPostedNotification::class, $notice);
        $this->assertFalse($notice->shouldSend($follower, 'mail'));
        $this->assertFalse($notice->shouldSend($follower, 'site'));
        $follower->notifyNow(unserialize($queued));
        $this->assertCount(1, $mailed);
    }

    /**
     * Enabling or disabling a module takes effect in the next process. Here the test's requests share
     * one application: answers remembered per request (scoped) and the controllers routes keep (with
     * what they were built with) are let go.
     */
    private function asANewProcess(): void
    {
        $this->app->forgetScopedInstances();
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $route->controller = null;
        }
    }

    private function system(SystemCategory $key, string $name, int $position): Category
    {
        $category = Category::system(CategoryId::generate(), $key, $name, CategorySlug::fromName($name), $position);
        app(CategoryRepositoryInterface::class)->save($category);

        return $category;
    }

    private function forumModerator(): UserModel
    {
        return $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);
    }

    private function url(Discussion $discussion): string
    {
        $stored = app(DiscussionRepositoryInterface::class)->find($discussion->id());
        $this->assertInstanceOf(Discussion::class, $stored);

        return app(ForumUrlsInterface::class)->discussion($stored);
    }
}
