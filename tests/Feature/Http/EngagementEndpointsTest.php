<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Services\FollowService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\CategoryFollowOrigin;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Likes, follows and the forum's preferences over HTTP, and what the pages show of them.
 */
final class EngagementEndpointsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $category;

    private Category $members;

    private UserModel $author;

    private UserModel $reader;

    private Discussion $discussion;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios', position: 1);
        $this->author = UserModel::factory()->create(['name' => 'Ana', 'display_name' => null]);
        $this->reader = UserModel::factory()->create(['name' => 'Luis', 'display_name' => null]);
        [$this->discussion, $this->post] = $this->aDiscussion($this->category, authorId: $this->author->id, title: 'La tumba');
    }

    public function test_a_reader_likes_a_post_and_takes_it_back(): void
    {
        $this->actingAs($this->reader)
            ->postJson($this->likeUrl())
            ->assertOk()
            ->assertJsonPath('likes.count', 1)
            ->assertJsonPath('likes.liked', true)
            ->assertJsonPath('likes.names', ['Luis']);

        $this->actingAs($this->reader)
            ->deleteJson($this->likeUrl())
            ->assertOk()
            ->assertJsonPath('likes.count', 0)
            ->assertJsonPath('likes.liked', false);
    }

    public function test_liking_needs_a_session_and_never_ones_own_post(): void
    {
        $this->postJson($this->likeUrl())->assertUnauthorized();
        $this->actingAs($this->author)->postJson($this->likeUrl())->assertForbidden();
    }

    public function test_a_post_the_person_may_not_read_is_not_found(): void
    {
        [, $hidden] = $this->aDiscussion($this->members, authorId: $this->author->id);

        $this->actingAs($this->reader)->postJson(route('forum.posts.like', ['post' => $hidden->id()->value]))->assertNotFound();
    }

    public function test_the_conversation_shows_each_posts_likes_and_how_the_reader_follows_it(): void
    {
        $this->actingAs($this->reader)->postJson($this->likeUrl())->assertOk();

        $this->actingAs($this->reader)
            ->get('/foro/t/1-la-tumba')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forum/Discussion', false)
                ->where('posts.0.likes.count', 1)
                ->where('posts.0.likes.liked', true)
                ->where('posts.0.likes.names', ['Luis'])
                ->where('posts.0.likes.canLike', true)
                ->where('posts.0.likes.url', $this->likeUrl())
                ->where('discussion.follow.level', 'normal')
                ->where('discussion.follow.url', route('forum.discussions.follow', ['discussion' => $this->discussion->id()->value])));

        $this->actingAs($this->author)
            ->get('/foro/t/1-la-tumba')
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.likes.count', 1)
                ->where('posts.0.likes.canLike', false)
                ->where('posts.0.likes.url', null));

        $this->asGuest();
        $this->get('/foro/t/1-la-tumba')
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.likes.count', 1)
                ->where('posts.0.likes.canLike', false)
                ->where('discussion.follow', null));
    }

    public function test_the_follow_level_of_a_discussion_is_saved(): void
    {
        $url = route('forum.discussions.follow', ['discussion' => $this->discussion->id()->value]);

        $this->actingAs($this->reader)->putJson($url, ['level' => 'following'])->assertOk()->assertJsonPath('level', 'following');
        $this->assertSame(FollowLevel::Following, app(FollowService::class)->levelOf($this->reader, $this->discussion));

        $this->actingAs($this->reader)->putJson($url, ['level' => 'muted'])->assertOk()->assertJsonPath('level', 'muted');
        $this->assertSame(FollowLevel::Muted, app(FollowService::class)->levelOf($this->reader, $this->discussion));

        $this->actingAs($this->reader)->putJson($url, ['level' => 'loud'])->assertUnprocessable();
    }

    public function test_following_a_category_from_its_page(): void
    {
        $this->actingAs($this->reader)
            ->get('/foro/c/general')
            ->assertInertia(fn (Assert $page) => $page
                ->where('category.follow.following', false)
                ->where('category.follow.url', route('forum.categories.follow', ['category' => 'general'])));

        $this->actingAs($this->reader)->putJson(route('forum.categories.follow', ['category' => 'general']), ['follow' => true])
            ->assertOk()
            ->assertJsonPath('following', true);

        $this->actingAs($this->reader)->get('/foro/c/general')->assertInertia(fn (Assert $page) => $page->where('category.follow.following', true));
        $this->asGuest();
        $this->get('/foro/c/general')->assertInertia(fn (Assert $page) => $page->where('category.follow', null));

        // A category the person may not read does not exist for them
        $this->actingAs($this->reader)->putJson(route('forum.categories.follow', ['category' => 'socios']), ['follow' => true])->assertNotFound();
    }

    public function test_the_following_filter_lists_only_the_discussions_the_reader_follows(): void
    {
        $this->aDiscussion($this->category, '2026-11-10 19:00:00', title: 'Otra');
        app(FollowsInterface::class)->setDiscussionLevel(strtolower($this->reader->id), $this->discussion->id(), FollowLevel::Following, CarbonImmutable::now());

        $this->actingAs($this->reader)
            ->get('/foro?siguiendo=1')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.following', true)
                ->has('discussions.items', 1)
                ->where('discussions.items.0.title', 'La tumba'));

        // Guests follow nothing: the filter is ignored
        $this->asGuest();
        $this->get('/foro?siguiendo=1')->assertInertia(fn (Assert $page) => $page->where('filters.following', false)->has('discussions.items', 2));
    }

    public function test_replying_and_starting_a_discussion_follow_it(): void
    {
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hola']]]]];

        $this->actingAs($this->reader)
            ->postJson(route('forum.posts.store', ['discussion' => $this->discussion->id()->value]), ['document' => $document])
            ->assertCreated();
        $this->assertSame([strtolower($this->reader->id)], app(FollowsInterface::class)->discussionFollowers($this->discussion->id()));

        $this->actingAs($this->reader)->putJson(route('forum.preferences.update'), ['autoFollow' => false])->assertOk()->assertJsonPath('autoFollow', false);
        $this->actingAs($this->reader)
            ->postJson('/foro/temas', ['category' => $this->category->id()->value, 'title' => 'Sin seguir', 'document' => $document])
            ->assertCreated();
        $this->assertSame(1, DB::table('forum_discussion_follows')->where('user_id', strtolower($this->reader->id))->count());
    }

    public function test_the_preferences_list_what_the_person_follows_and_may_still_read(): void
    {
        $follows = app(FollowsInterface::class);
        $follows->setDiscussionLevel(strtolower($this->reader->id), $this->discussion->id(), FollowLevel::Following, CarbonImmutable::now());
        $follows->followCategory(strtolower($this->reader->id), $this->category->id(), CategoryFollowOrigin::Person, CarbonImmutable::now());
        $follows->followCategory(strtolower($this->reader->id), $this->members->id(), CategoryFollowOrigin::Person, CarbonImmutable::now());

        $this->getJson(route('forum.preferences.show'))->assertUnauthorized();
        $this->actingAs($this->reader)
            ->getJson(route('forum.preferences.show'))
            ->assertOk()
            ->assertJsonPath('autoFollow', true)
            ->assertJsonCount(1, 'categories')
            ->assertJsonPath('categories.0.name', 'General')
            ->assertJsonPath('categories.0.followUrl', route('forum.categories.follow', ['category' => 'general']))
            ->assertJsonCount(1, 'discussions')
            ->assertJsonPath('discussions.0.title', 'La tumba')
            ->assertJsonPath('discussions.0.url', '/foro/t/1-la-tumba');
    }

    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function likeUrl(): string
    {
        return route('forum.posts.like', ['post' => $this->post->id()->value]);
    }
}
