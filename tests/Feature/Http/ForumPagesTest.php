<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The public pages: /foro, /foro/c/<category> and /foro/t/<number>-<slug>[/<post>]. What a
 * reader may not read does not exist for them (404); addresses that are not the right one
 * redirect to it; private pages carry noindex.
 */
final class ForumPagesTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $public;

    private Category $private;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->public = $this->aCategoryFor(CategoryAudience::everyone(), 'general', position: 0);
        $this->private = $this->aCategoryFor(CategoryAudience::signedIn(), 'socios', position: 1);
    }

    public function test_the_front_page_lists_readable_categories_and_recent_discussions(): void
    {
        $this->aCategoryFor(CategoryAudience::everyone(), 'reglas', parent: $this->public);
        $this->aDiscussion($this->public, '2026-11-10 10:00:00', title: 'Hola a todos');
        $this->aDiscussion($this->private, '2026-11-10 11:00:00', title: 'Solo con sesión');

        $this->get('/foro')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forum/Index', false)
                ->has('categories', 1)
                ->where('categories.0.name', 'General')
                ->where('categories.0.url', '/foro/c/general')
                ->where('categories.0.discussionCount', 1)
                ->where('categories.0.lastPost.discussionTitle', 'Hola a todos')
                ->where('categories.0.children.0.name', 'Reglas')
                ->has('discussions.items', 1)
                ->where('discussions.items.0.title', 'Hola a todos')
                ->where('discussions.items.0.url', '/foro/t/1-hola-a-todos')
                ->where('discussions.items.0.categoryId', $this->public->id()->value)
                ->where('discussions.items.0.isUnread', null)
                ->where('markReadUrl', null)
                ->where('canStart', false)
                ->where('seo.robots', fn (?string $robots): bool => $robots === null || ! str_contains($robots, 'noindex')));

        $this->actingAs($this->anAccount())
            ->get('/foro')
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories', 2)
                ->has('discussions.items', 2)
                ->where('discussions.items.0.title', 'Solo con sesión')
                ->where('discussions.items.0.isUnread', true)
                ->where('canStart', true)
                ->where('markReadUrl', route('forum.read')));
    }

    public function test_a_category_page_lists_its_discussions_and_its_subcategories_pinned_first(): void
    {
        $child = $this->aCategoryFor(CategoryAudience::everyone(), 'presentaciones', parent: $this->public);
        [$rules] = $this->aDiscussion($this->public, '2026-11-01 10:00:00', title: 'Normas del foro');
        $rules->pin();
        app(ForumWriter::class)->saveDiscussion($rules);
        $this->aDiscussion($child, '2026-11-02 10:00:00', title: 'Me presento');
        $this->aDiscussion($this->private, '2026-11-03 10:00:00', title: 'Privado');

        $this->get('/foro/c/general')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forum/Category', false)
                ->where('category.name', 'General')
                ->where('category.isPublicationSpace', false)
                ->where('category.readOnly', false)
                ->where('subcategories.0.name', 'Presentaciones')
                ->where('discussions.pinned.0.title', 'Normas del foro')
                ->has('discussions.items', 1)
                ->where('discussions.items.0.title', 'Me presento')
                ->where('discussions.items.0.category.name', 'Presentaciones')
                ->where('seo.title', 'General'));

        $this->get('/foro/c/presentaciones')
            ->assertInertia(fn (Assert $page) => $page->where('category.parent.name', 'General'));

        // A read-only parent makes its subcategories read-only too
        $this->public->makeReadOnly();
        app(CategoryManager::class)->save($this->public);
        $this->get('/foro/c/presentaciones')
            ->assertInertia(fn (Assert $page) => $page->where('category.readOnly', true));
    }

    public function test_what_a_reader_may_not_read_is_not_found(): void
    {
        [$hidden] = $this->aDiscussion($this->public, title: 'Oculto');
        $hidden->hide();
        app(ForumWriter::class)->saveDiscussion($hidden);
        [$private] = $this->aDiscussion($this->private, title: 'Privado');

        $this->get('/foro/c/socios')->assertNotFound();
        $this->get('/foro/c/no-existe')->assertNotFound();
        $this->get('/foro/t/'.$private->number().'-privado')->assertNotFound();
        $this->get('/foro/t/'.$hidden->number().'-oculto')->assertNotFound();
        $this->get('/foro/t/999-nada')->assertNotFound();

        $this->actingAs($this->anAccount())->get('/foro/t/'.$hidden->number().'-oculto')->assertNotFound();
        $this->actingAs($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM))
            ->get('/foro/t/'.$hidden->number().'-oculto')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('discussion.isHidden', true));
    }

    public function test_a_private_page_is_not_indexed_and_a_public_one_is(): void
    {
        [$public] = $this->aDiscussion($this->public, title: 'Público');
        [$private] = $this->aDiscussion($this->private, title: 'Privado');
        $reader = $this->anAccount();

        $this->actingAs($reader)->get('/foro/c/socios')
            ->assertInertia(fn (Assert $page) => $page->where('seo.robots', fn (?string $robots): bool => str_contains((string) $robots, 'noindex')));
        $this->actingAs($reader)->get('/foro/t/'.$private->number().'-privado')
            ->assertInertia(fn (Assert $page) => $page->where('seo.robots', fn (?string $robots): bool => str_contains((string) $robots, 'noindex')));
        $this->actingAs($reader)->get('/foro/t/'.$public->number().'-publico')
            ->assertInertia(fn (Assert $page) => $page
                ->where('seo.robots', fn (?string $robots): bool => $robots === null || ! str_contains($robots, 'noindex'))
                ->where('seo.title', 'Público')
                ->where('seo.description', 'Primer mensaje'));
    }

    public function test_a_wrong_slug_or_number_redirects_to_the_right_address(): void
    {
        [$discussion] = $this->aDiscussion($this->public, title: 'Mesa del viernes');
        $this->aReply($discussion, '2026-11-10 19:00:00');
        $number = $discussion->number();

        $this->get("/foro/t/{$number}")->assertRedirect("/foro/t/{$number}-mesa-del-viernes")->assertStatus(301);
        $this->get("/foro/t/{$number}-otro-titulo")->assertRedirect("/foro/t/{$number}-mesa-del-viernes");
        $this->get("/foro/t/{$number}-otro-titulo/2")->assertRedirect("/foro/t/{$number}-mesa-del-viernes/2");
        $this->get("/foro/t/{$number}-mesa-del-viernes/1")->assertRedirect("/foro/t/{$number}-mesa-del-viernes");
        // Past the last post: the last one
        $this->get("/foro/t/{$number}-mesa-del-viernes/40")->assertRedirect("/foro/t/{$number}-mesa-del-viernes/2");
        $this->get("/foro/t/{$number}-mesa-del-viernes/2")->assertOk();
    }

    public function test_the_conversation_is_paged_by_post_number_and_opens_at_the_post_asked_for(): void
    {
        $this->setPostsPerPage(10);
        [$discussion] = $this->aDiscussion($this->public, '2026-11-01 10:00:00', title: 'Larga');
        for ($i = 2; $i <= 25; $i++) {
            $this->aReply($discussion, sprintf('2026-11-01 10:%02d:00', $i), text: "Mensaje {$i}");
        }
        $base = '/foro/t/'.$discussion->number().'-larga';

        $this->get("{$base}/14")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forum/Discussion', false)
                ->has('posts', 10)
                ->where('posts.0.number', 11)
                ->where('posts.9.number', 20)
                ->where('posts.3.url', "{$base}/14")
                ->where('posts.3.html', '<p>Mensaje 14</p>')
                ->where('focusNumber', 14)
                ->where('pagination.page', 2)
                ->where('pagination.pageCount', 3)
                ->where('pagination.previousUrl', $base)
                ->where('pagination.nextUrl', "{$base}/21")
                ->where('seo.canonicalUrl', url("{$base}/11"))
                ->where('reply.canPost', false)
                ->where('readUrl', null));

        $this->get($base)->assertInertia(fn (Assert $page) => $page
            ->where('posts.0.number', 1)
            ->where('focusNumber', null)
            ->where('pagination.previousUrl', null));
    }

    public function test_a_reader_is_taken_to_their_first_unread_post(): void
    {
        $this->setPostsPerPage(10);
        $reader = $this->anAccount();
        [$discussion] = $this->aDiscussion($this->public, '2026-11-01 10:00:00', title: 'Larga');
        for ($i = 2; $i <= 15; $i++) {
            $this->aReply($discussion, sprintf('2026-11-01 10:%02d:00', $i));
        }
        $base = '/foro/t/'.$discussion->number().'-larga';

        // Never opened: from the start, and opening it marks nothing
        $this->actingAs($reader)->get($base)->assertInertia(fn (Assert $page) => $page
            ->where('focusNumber', null)
            ->where('lastReadNumber', 0)
            ->where('posts.0.number', 1)
            ->where('readUrl', route('forum.discussions.read', ['discussion' => $discussion->id()->value])));
        $this->assertReadThrough($reader, $discussion, 0);

        app(DiscussionReadsInterface::class)->advance($reader->id, $discussion->id(), 12, new DateTimeImmutable('2026-11-02'));
        $this->actingAs($reader)->get($base)->assertInertia(fn (Assert $page) => $page
            ->where('focusNumber', 13)
            ->where('posts.0.number', 11));

        // Everything read: the last post
        app(DiscussionReadsInterface::class)->advance($reader->id, $discussion->id(), 15, new DateTimeImmutable('2026-11-02'));
        $this->actingAs($reader)->get($base)->assertInertia(fn (Assert $page) => $page->where('focusNumber', 15));
    }

    public function test_the_page_reports_what_it_showed_and_the_mark_never_goes_back_or_ahead(): void
    {
        $reader = $this->anAccount();
        [$discussion] = $this->aDiscussion($this->public, title: 'Corto');
        $this->aReply($discussion, '2026-11-10 19:00:00');
        $this->aReply($discussion, '2026-11-10 20:00:00');
        $url = route('forum.discussions.read', ['discussion' => $discussion->id()->value]);
        $this->postJson($url, ['number' => 2])->assertUnauthorized();

        $this->actingAs($reader)->postJson($url, ['number' => 2])->assertOk();
        $this->assertReadThrough($reader, $discussion, 2);

        $this->actingAs($reader)->postJson($url, ['number' => 1])->assertOk();
        $this->assertReadThrough($reader, $discussion, 2);

        $this->actingAs($reader)->postJson($url, ['number' => 99])->assertOk();
        $this->assertReadThrough($reader, $discussion, 3);

        $this->actingAs($reader)->postJson($url, ['number' => 'x'])->assertUnprocessable();

        [$private] = $this->aDiscussion($this->private, title: 'Privado');
        $this->actingAs($this->anAccount())->postJson(route('forum.discussions.read', ['discussion' => $private->id()->value]), ['number' => 1])->assertOk();
    }

    public function test_marking_a_category_or_the_forum_read_covers_what_is_there_now(): void
    {
        $reader = $this->anAccount();
        $child = $this->aCategoryFor(CategoryAudience::everyone(), 'presentaciones', parent: $this->public);
        [$inChild] = $this->aDiscussion($child, '2026-11-01 10:00:00', title: 'Hola');
        [$inPrivate] = $this->aDiscussion($this->private, '2026-11-01 11:00:00', title: 'Privado');
        $this->travelTo(new DateTimeImmutable('2026-11-05 12:00:00'));
        $this->post(route('forum.read'))->assertRedirect(route('login'));

        $this->actingAs($reader)->from('/foro/c/general')->post(route('forum.categories.read', ['category' => 'general']))
            ->assertRedirect('/foro/c/general')
            ->assertSessionHas('success');
        $this->assertReadThrough($reader, $inChild, 1);
        $this->assertReadThrough($reader, $inPrivate, 0);

        $this->actingAs($reader)->post(route('forum.read'))->assertRedirect();
        $this->assertReadThrough($reader, $inPrivate, 1);

        $this->actingAs($reader)->post(route('forum.categories.read', ['category' => 'no-existe']))->assertNotFound();
    }

    public function test_whoever_replies_or_starts_a_discussion_has_read_it_up_to_their_post(): void
    {
        $author = $this->anAccount();
        [$discussion] = $this->aDiscussion($this->public, title: 'Corto');
        $this->aReply($discussion, '2026-11-10 19:00:00');

        $this->actingAs($author)->postJson(route('forum.posts.store', ['discussion' => $discussion->id()->value]), [
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Yo también']]]]],
        ])->assertCreated();

        $this->assertReadThrough($author, $discussion, 3);
    }

    private function assertReadThrough(UserModel $reader, Discussion $discussion, int $expected): void
    {
        $this->assertSame($expected, app(DiscussionReadsInterface::class)->readThrough(strtolower($reader->id), $discussion->id(), $discussion->categoryId()));
    }

    private function setPostsPerPage(int $perPage): void
    {
        config(['modules.settings.forum.posts_per_page' => $perPage]);
    }
}
