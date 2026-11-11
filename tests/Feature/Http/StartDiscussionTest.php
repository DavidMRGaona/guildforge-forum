<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Domain\Forum\Events\ForumDiscussionStarted;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Starting a discussion: the form offers the categories the person may start one in and the
 * tags already in use; the discussion is saved with its first post, and one guests can read in
 * a category the settings chose is announced once.
 */
final class StartDiscussionTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string GAME_SYSTEM = 'game-system.0d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private UserModel $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->author = $this->anAccount();
    }

    public function test_the_form_offers_where_the_person_may_start_a_discussion(): void
    {
        $this->aCategoryFor(CategoryAudience::everyone(), 'anuncios', write: CategoryAudience::associationMembers());
        $this->systemCategory();
        [$tagged] = $this->aDiscussion($this->general);
        app(DiscussionRepositoryInterface::class)->replaceTopics($tagged->id(), [new DiscussionTopic(self::GAME_SYSTEM, 'Pathfinder')]);

        $this->actingAs($this->author)
            ->get('/foro/nuevo?categoria=general')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forum/Create', false)
                ->has('categories', 1)
                ->where('categories.0.name', 'General')
                ->where('categories.0.canModerate', false)
                ->where('selectedCategoryId', $this->general->id()->value)
                ->where('topics.0.label', 'Pathfinder')
                ->where('editor.uploadUrl', null)
                ->where('seo.robots', fn (?string $robots): bool => str_contains((string) $robots, 'noindex')));
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/foro/nuevo')->assertRedirect(route('login'));
        $this->postJson('/foro/temas', [])->assertUnauthorized();
    }

    public function test_a_discussion_is_started_with_its_first_post_and_tags(): void
    {
        [$tagged] = $this->aDiscussion($this->general);
        app(DiscussionRepositoryInterface::class)->replaceTopics($tagged->id(), [new DiscussionTopic(self::GAME_SYSTEM, 'Pathfinder')]);

        $response = $this->start(['title' => '  Buscamos   jugadores ', 'topics' => [self::GAME_SYSTEM]])
            ->assertCreated()
            ->assertJsonPath('url', '/foro/t/2-buscamos-jugadores');

        $discussion = app(DiscussionRepositoryInterface::class)->findByNumber(2);
        $this->assertInstanceOf(Discussion::class, $discussion);
        $this->assertSame('Buscamos jugadores', $discussion->title()->value);
        $this->assertSame(strtolower($this->author->id), $discussion->authorId());
        $this->assertFalse($discussion->isPinned());
        $this->assertSame(['Pathfinder'], array_map(static fn (DiscussionTopic $t): string => $t->label, app(DiscussionRepositoryInterface::class)->topicsOf($discussion->id())));

        $posts = app(PostRepositoryInterface::class)->inDiscussion($discussion->id());
        $this->assertCount(1, $posts);
        $this->assertSame(1, $posts[0]->number()?->value);
        $this->assertSame('Hola a todos', $posts[0]->body()->plainText);
        $this->assertSame(1, app(DiscussionReadsInterface::class)->readThrough(strtolower($this->author->id), $discussion->id(), $discussion->categoryId()));

        $this->get($response->json('url'))->assertOk();
    }

    public function test_what_the_form_cannot_send_is_refused_field_by_field(): void
    {
        $private = $this->aCategoryFor(CategoryAudience::associationMembers(), 'privada');
        $system = $this->systemCategory();

        $this->start(['title' => 'No'])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->start(['title' => str_repeat('a', 121)])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->start(['category' => $private->id()->value])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->start(['category' => $system->id()->value])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->start(['topics' => ['game-system.unknown']])->assertUnprocessable()->assertJsonValidationErrors('topics');
        $this->start(['pinned' => true])->assertUnprocessable()->assertJsonValidationErrors('moderation');
        $this->start([], document: ['type' => 'doc', 'content' => [['type' => 'script']]])->assertUnprocessable()->assertJsonPath('reason', 'unknown_node');

        $this->assertNull(app(DiscussionRepositoryInterface::class)->findByNumber(1));
    }

    public function test_moderators_may_start_it_pinned_and_closed(): void
    {
        $moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);

        $this->start(['pinned' => true, 'closed' => true], as: $moderator)->assertCreated();

        $discussion = app(DiscussionRepositoryInterface::class)->findByNumber(1);
        $this->assertInstanceOf(Discussion::class, $discussion);
        $this->assertTrue($discussion->isPinned());
        $this->assertSame(DiscussionStatus::Closed, $discussion->status());
    }

    public function test_a_discussion_guests_can_read_in_a_chosen_category_is_announced_once(): void
    {
        Event::fake([ForumDiscussionStarted::class]);
        $this->announce($this->general);

        $this->start(['title' => 'Torneo de primavera'])->assertCreated();

        Event::assertDispatchedTimes(ForumDiscussionStarted::class, 1);
        Event::assertDispatched(ForumDiscussionStarted::class, fn (ForumDiscussionStarted $event): bool => $event->title === 'Torneo de primavera'
            && $event->url === url('/foro/t/1-torneo-de-primavera')
            && $event->excerpt === 'Hola a todos'
            && $event->tags === ['General']);
    }

    public function test_nothing_is_announced_outside_the_rules(): void
    {
        Event::fake([ForumDiscussionStarted::class]);
        $private = $this->aCategoryFor(CategoryAudience::signedIn(), 'privada');
        $notChosen = $this->aCategoryFor(CategoryAudience::everyone(), 'charla');
        $this->announce($this->general, $private);

        // Chosen but private, and public but not chosen
        $this->start(['category' => $private->id()->value])->assertCreated();
        $this->start(['category' => $notChosen->id()->value])->assertCreated();
        // A discussion that is not saved is not announced
        $this->start(['title' => 'x'])->assertUnprocessable();

        Event::assertNotDispatched(ForumDiscussionStarted::class);
    }

    public function test_with_no_category_chosen_nothing_is_announced(): void
    {
        Event::fake([ForumDiscussionStarted::class]);

        $this->start()->assertCreated();

        Event::assertNotDispatched(ForumDiscussionStarted::class);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>|null  $document
     * @return TestResponse<Response>
     */
    private function start(array $fields = [], ?array $document = null, ?UserModel $as = null): TestResponse
    {
        $body = [
            'title' => 'Hola',
            'category' => $this->general->id()->value,
            ...$fields,
            'document' => $document ?? ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hola a todos']]]]],
        ];

        return $this->actingAs($as ?? $this->author)->postJson('/foro/temas', $body);
    }

    private function systemCategory(): Category
    {
        $category = Category::system(CategoryId::generate(), SystemCategory::GameTables, 'Mesas', new CategorySlug('mesas'), 5);
        app(CategoryRepositoryInterface::class)->save($category);

        return $category;
    }

    private function announce(Category ...$categories): void
    {
        config(['modules.settings.forum.announced_categories' => array_map(static fn (Category $category): string => $category->id()->value, $categories)]);
    }
}
