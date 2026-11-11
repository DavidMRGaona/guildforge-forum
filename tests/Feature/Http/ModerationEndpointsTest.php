<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Domain\Forum\Events\ForumDiscussionStarted;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ModerationLogModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The moderation menus from the forum, newcomers' posts that wait for approval, and the
 * discussions-per-hour limit.
 */
final class ModerationEndpointsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MODERATE = 'forum:forum.moderate';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private UserModel $author;

    private UserModel $moderator;

    private Discussion $discussion;

    private Post $firstPost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->author = $this->anAccount();
        $this->moderator = $this->anAccount(self::MODERATE);
        [$this->discussion, $this->firstPost] = $this->aDiscussion($this->general, authorId: strtolower($this->author->id));
    }

    public function test_the_page_offers_the_menu_only_to_its_moderators(): void
    {
        $url = '/foro/t/1-sesion-del-viernes';

        $this->actingAs($this->author)->get($url)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('moderation', null)->where('posts.0.moderationUrl', null));

        $other = $this->aCategoryFor(CategoryAudience::everyone(), 'otra');
        $this->actingAs($this->moderator)->get($url)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('moderation.actionsUrl', route('forum.moderation.discussions', ['discussion' => $this->discussion->id()->value]))
                ->where('moderation.deleteUrl', route('forum.moderation.discussions.destroy', ['discussion' => $this->discussion->id()->value]))
                ->where('moderation.moveTargets', [['id' => $other->id()->value, 'name' => 'Otra', 'parentName' => null]])
                ->where('posts.0.moderationUrl', route('forum.moderation.posts', ['post' => $this->firstPost->id()->value]))
                // A moderator may edit anyone's post, never delete it
                ->where('posts.0.canEdit', true)
                ->where('posts.0.canDelete', false));
    }

    public function test_each_discussion_action_answers_the_new_state(): void
    {
        $this->act('pin')->assertOk()->assertJsonPath('discussion.isPinned', true);
        $this->act('close', ['reason' => 'Resuelto'])->assertOk()->assertJsonPath('discussion.isClosed', true);
        $this->act('reopen')->assertOk()->assertJsonPath('discussion.isClosed', false);
        $this->act('rename', ['title' => 'Otro título'])->assertOk()
            ->assertJsonPath('discussion.title', 'Otro título')
            ->assertJsonPath('discussion.url', '/foro/t/1-otro-titulo');
        $this->act('hide')->assertOk()->assertJsonPath('discussion.isHidden', true);
        $this->act('show')->assertOk()->assertJsonPath('discussion.isHidden', false);

        $this->assertSame(6, ModerationLogModel::query()->count());
    }

    public function test_an_action_on_what_is_already_so_is_a_conflict_and_a_refusal_is_unprocessable(): void
    {
        $this->act('reopen')->assertStatus(409);
        $this->act('rename', ['title' => 'x'])->assertUnprocessable()->assertJsonPath('reason', 'title');
        $this->act('move', ['category' => $this->general->id()->value])->assertUnprocessable()->assertJsonPath('reason', 'same_category');
        $this->act('fly')->assertUnprocessable()->assertJsonValidationErrors('action');
    }

    public function test_moving_through_the_menu(): void
    {
        $other = $this->aCategoryFor(CategoryAudience::everyone(), 'otra');

        $this->act('move', ['category' => $other->id()->value])->assertOk();

        $this->assertTrue(app(DiscussionRepositoryInterface::class)->findOrFail($this->discussion->id())->categoryId()->equals($other->id()));
    }

    public function test_who_does_not_moderate_is_forbidden_and_who_cannot_read_gets_not_found(): void
    {
        $this->postJson($this->actionsUrl(), ['action' => 'pin'])->assertUnauthorized();
        $this->act('pin', as: $this->author)->assertForbidden();

        $this->act('hide')->assertOk();
        $this->act('pin', as: $this->author)->assertNotFound();
    }

    public function test_deleting_needs_the_box_ticked(): void
    {
        $url = route('forum.moderation.discussions.destroy', ['discussion' => $this->discussion->id()->value]);

        $this->actingAs($this->moderator)->deleteJson($url, ['reason' => 'Spam'])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assertInstanceOf(Discussion::class, app(DiscussionRepositoryInterface::class)->find($this->discussion->id()));

        $this->actingAs($this->author)->deleteJson($url, ['confirmed' => true])->assertForbidden();

        $this->actingAs($this->moderator)->deleteJson($url, ['confirmed' => true, 'reason' => 'Spam'])
            ->assertOk()
            ->assertJsonPath('url', '/foro/c/general');
        $this->assertNull(app(DiscussionRepositoryInterface::class)->find($this->discussion->id()));
    }

    public function test_hiding_a_post_shows_the_reason_to_its_author_and_not_to_others(): void
    {
        $reply = $this->aReply($this->discussion, '2026-11-10 18:30:00');

        $this->actingAs($this->moderator)->postJson($this->postUrl($reply), ['action' => 'hide', 'reason' => 'Fuera de tono'])
            ->assertOk()
            ->assertJsonPath('post.status', 'hidden')
            ->assertJsonPath('post.hiddenReason', 'Fuera de tono');

        $this->actingAs($this->anAccount())->get('/foro/t/1-sesion-del-viernes')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posts.1.html', null)
                ->where('posts.1.hiddenReason', null)
                ->where('posts.1.placeholder', 'Mensaje oculto por la moderación'));
    }

    public function test_a_moderator_edits_someone_elses_post_and_the_edit_is_logged(): void
    {
        $this->actingAs($this->moderator)
            ->patchJson("/foro/mensajes/{$this->firstPost->id()->value}", ['document' => $this->text('Corregido por la moderación')])
            ->assertOk()
            ->assertJsonPath('post.html', '<p>Corregido por la moderación</p>');

        $this->assertSame('post_edited', ModerationLogModel::query()->sole()->action);

        // Anyone else is still forbidden
        $this->actingAs($this->anAccount())
            ->patchJson("/foro/mensajes/{$this->firstPost->id()->value}", ['document' => $this->text('Mío')])
            ->assertForbidden();
    }

    public function test_a_newcomers_reply_waits_where_the_category_asks_for_approval(): void
    {
        $this->askApproval(1);
        $newcomer = $this->anAccount();

        $this->actingAs($newcomer)->postJson($this->replyUrl(), ['document' => $this->text('Hola')])
            ->assertCreated()
            ->assertJsonPath('post.status', 'pending')
            ->assertJsonPath('post.number', null);

        // The author of the discussion has one post published: they post freely
        $this->actingAs($this->author)->postJson($this->replyUrl(), ['document' => $this->text('Bienvenido')])
            ->assertCreated()
            ->assertJsonPath('post.number', 2);

        // So do moderators
        $this->actingAs($this->moderator)->postJson($this->replyUrl(), ['document' => $this->text('Hola')])
            ->assertCreated()
            ->assertJsonPath('post.number', 3);

        $pending = app(PostRepositoryInterface::class)->pendingIn($this->discussion->id());
        $this->assertCount(1, $pending);

        $this->actingAs($this->moderator)->postJson($this->postUrl($pending[0]), ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('post.number', 4)
            ->assertJsonPath('post.status', 'visible');
    }

    public function test_a_newcomers_discussion_waits_hidden_from_everyone_but_them_and_moderation(): void
    {
        Event::fake([ForumDiscussionStarted::class]);
        config(['modules.settings.forum.announced_categories' => [$this->general->id()->value]]);
        $this->askApproval(1);
        $newcomer = $this->anAccount();

        $url = $this->actingAs($newcomer)->postJson('/foro/temas', [
            'title' => 'Me presento',
            'category' => $this->general->id()->value,
            'document' => $this->text('Hola a todos'),
        ])->assertCreated()->json('url');
        $this->assertIsString($url);
        Event::assertNotDispatched(ForumDiscussionStarted::class);

        $waiting = app(DiscussionRepositoryInterface::class)->findByNumber(2);
        $this->assertInstanceOf(Discussion::class, $waiting);
        $this->assertTrue($waiting->isAwaitingApproval());

        $this->actingAs($newcomer)->get($url)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('discussion.isAwaitingApproval', true)->where('reply.canPost', false));
        $this->actingAs($this->moderator)->get($url)->assertOk();
        $this->actingAs($this->author)->get($url)->assertNotFound();
        $this->get($url)->assertNotFound();

        [$first] = app(PostRepositoryInterface::class)->pendingIn($waiting->id());
        $this->actingAs($this->moderator)->postJson($this->postUrl($first), ['action' => 'approve'])->assertOk();

        $this->get($url)->assertOk();
        Event::assertDispatchedTimes(ForumDiscussionStarted::class, 1);
    }

    public function test_rejecting_a_post_takes_it_away(): void
    {
        $reply = $this->aReply($this->discussion, '2026-11-10 18:30:00', needsApproval: true);

        $this->actingAs($this->moderator)->postJson($this->postUrl($reply), ['action' => 'reject', 'reason' => 'Spam'])
            ->assertOk()
            ->assertJsonPath('removed', true);

        $this->assertNull(app(PostRepositoryInterface::class)->find($reply->id()));
        $this->actingAs($this->moderator)->postJson($this->postUrl($reply), ['action' => 'reject'])->assertNotFound();
    }

    public function test_starting_more_discussions_than_the_settings_allow_in_an_hour_answers_too_many_requests(): void
    {
        config(['modules.settings.forum.discussions_per_hour' => 1]);
        RateLimiter::clear('forum-discussions:'.strtolower($this->author->id));
        $start = fn (string $title): TestResponse => $this->actingAs($this->author)->postJson('/foro/temas', [
            'title' => $title,
            'category' => $this->general->id()->value,
            'document' => $this->text('Hola'),
        ]);

        $start('Primer tema')->assertCreated();
        $start('Segundo tema')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'última hora'));
    }

    public function test_the_moderation_endpoints_have_a_limit_per_person(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['forum.moderation.discussions', 'forum.moderation.discussions.destroy', 'forum.moderation.posts'] as $name) {
            $middleware = $routes->getByName($name)?->gatherMiddleware() ?? [];
            $this->assertNotEmpty(array_filter($middleware, static fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, 'throttle:') && str_ends_with($entry, 'forum-moderation')), $name);
        }
    }

    private function askApproval(int $posts): void
    {
        $this->general->requireApprovalFor($posts);
        app(CategoryRepositoryInterface::class)->save($this->general);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return TestResponse<Response>
     */
    private function act(string $action, array $fields = [], ?UserModel $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->moderator)->postJson($this->actionsUrl(), ['action' => $action, ...$fields]);
    }

    private function actionsUrl(): string
    {
        return route('forum.moderation.discussions', ['discussion' => $this->discussion->id()->value]);
    }

    private function postUrl(Post $post): string
    {
        return route('forum.moderation.posts', ['post' => $post->id()->value]);
    }

    private function replyUrl(): string
    {
        return route('forum.posts.store', ['discussion' => $this->discussion->id()->value]);
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }
}
