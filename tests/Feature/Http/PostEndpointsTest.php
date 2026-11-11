<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRevisionRepositoryInterface;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeForumAccess;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The editor's endpoints: replying, editing and deleting one's own posts, and moderators
 * reading versions. Documents are stored exactly as sent or refused with a reason.
 */
final class PostEndpointsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private FakeForumAccess $access;

    private UserModel $author;

    private Discussion $discussion;

    private Post $firstPost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->access = new FakeForumAccess;
        $this->app->instance(ForumAccessInterface::class, $this->access);

        $this->author = UserModel::factory()->create(['name' => 'Ana Pérez', 'display_name' => null]);
        $this->access->letWrite($this->author);
        [$this->discussion, $this->firstPost] = $this->aDiscussion($this->aCategory(), authorId: $this->author->id);
    }

    public function test_a_reply_is_stored_exactly_as_the_editor_sent_it(): void
    {
        $document = $this->doc([$this->paragraph([
            ['type' => 'text', 'text' => 'Hola'],
            ['type' => 'text', 'text' => ' a todos ', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => '  '],
        ])]);

        $response = $this->reply($document)->assertCreated();

        $response->assertJsonPath('post.number', 2)
            ->assertJsonPath('post.status', 'visible')
            ->assertJsonPath('post.html', '<p>Hola<strong> a todos </strong>  </p>')
            ->assertJsonPath('post.canEdit', true)
            ->assertJsonPath('post.canDelete', true);

        $stored = $this->posts()->inDiscussion($this->discussion->id())[1];
        $this->assertSame($document, $stored->body()->document);
        $this->assertSame('Hola a todos', $stored->body()->plainText);
        $this->assertSame(strtolower($this->author->id), $stored->authorId());
    }

    public function test_an_invalid_document_is_refused_with_its_reason_and_nothing_is_stored(): void
    {
        $this->reply($this->doc([$this->paragraph([['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]])]))
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'invalid_link')
            ->assertJsonPath('path', 'content.0.content.0.marks.0')
            ->assertJsonPath('message', 'Los enlaces solo pueden ir a direcciones http, https o mailto.');

        $this->reply('<p>hola</p>')->assertUnprocessable()->assertJsonPath('reason', 'not_a_document');
        $this->actingAs($this->author)
            ->postJson($this->replyUrl(), [])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'not_a_document');

        $this->assertCount(1, $this->posts()->inDiscussion($this->discussion->id()));
    }

    public function test_mentions_must_point_at_accounts_that_can_be_mentioned(): void
    {
        $friend = UserModel::factory()->create();
        $anonymized = UserModel::factory()->create(['anonymized_at' => now()]);

        $this->reply($this->doc([$this->paragraph([['type' => 'mention', 'attrs' => ['id' => strtolower($friend->id)]]])]))
            ->assertCreated();
        $this->reply($this->doc([$this->paragraph([['type' => 'mention', 'attrs' => ['id' => strtolower($anonymized->id)]]])]))
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'unknown_account');
        $this->reply($this->doc([$this->paragraph([['type' => 'mention', 'attrs' => ['id' => '00000000-0000-4000-8000-000000000000']]])]))
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'unknown_account');
    }

    public function test_quotes_must_point_at_posts_the_author_may_read(): void
    {
        $quote = fn (string $postId, string $text = 'Respuesta'): array => $this->doc([['type' => 'postQuote', 'attrs' => ['postId' => $postId], 'content' => [$this->paragraph([['type' => 'text', 'text' => $text]])]]]);

        $this->reply($quote($this->firstPost->id()->value, 'Primer mensaje'))->assertCreated();
        // Formatting and spacing do not matter, only the words
        $this->reply($quote($this->firstPost->id()->value, 'PRIMER  mensaje'))->assertCreated();

        // Words the original never said cannot be put in its author's mouth
        $this->reply($quote($this->firstPost->id()->value, 'Lo dicho'))
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'invalid_quote');

        $this->reply($quote('00000000-0000-4000-8000-000000000000', 'Lo que sea'))
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'unknown_post');

        $hidden = $this->aReply($this->discussion, '2026-11-10 18:30:00');
        $hidden->hide();
        app(ForumWriter::class)->savePost($hidden);
        $this->reply($quote($hidden->id()->value))
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'unknown_post');
    }

    public function test_who_cannot_read_the_discussion_gets_not_found_and_who_cannot_write_is_forbidden(): void
    {
        $stranger = UserModel::factory()->create();
        $reader = UserModel::factory()->create();
        $this->access->letRead($reader);

        $this->postJson($this->replyUrl(), ['document' => $this->text('Hola')])->assertUnauthorized();
        $this->actingAs($stranger)->postJson($this->replyUrl(), ['document' => $this->text('Hola')])->assertNotFound();
        $this->actingAs($reader)->postJson($this->replyUrl(), ['document' => $this->text('Hola')])->assertForbidden();
        $this->actingAs($this->author)->postJson('/foro/temas/00000000-0000-4000-8000-000000000000/mensajes', ['document' => $this->text('Hola')])->assertNotFound();
    }

    public function test_a_closed_discussion_takes_replies_only_from_its_moderators(): void
    {
        $this->discussion->close();
        app(ForumWriter::class)->saveDiscussion($this->discussion);
        $moderator = UserModel::factory()->create();
        $this->access->letModerate($moderator);

        $this->reply($this->text('Hola'))->assertForbidden();
        $this->actingAs($moderator)->postJson($this->replyUrl(), ['document' => $this->text('Cerrado')])->assertCreated();
    }

    public function test_posting_faster_than_the_settings_allow_answers_too_many_requests(): void
    {
        config(['modules.settings.forum.posts_per_minute' => 2]);
        RateLimiter::clear('forum-posts:'.strtolower($this->author->id));

        $this->reply($this->text('Uno'))->assertCreated();
        $this->reply($this->text('Dos'))->assertCreated();
        $this->reply($this->text('Tres'))
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        // An invalid document is refused before it counts
        RateLimiter::clear('forum-posts:'.strtolower($this->author->id));
        $this->reply($this->doc([['type' => 'paragraph']]))->assertUnprocessable();
        $this->reply($this->text('Cuatro'))->assertCreated();
    }

    public function test_every_endpoint_has_a_limit_per_person(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['forum.posts.update' => 'forum-edits', 'forum.posts.destroy' => 'forum-edits', 'forum.mentions.index' => 'forum-mentions', 'forum.images.store' => 'forum-images'] as $name => $key) {
            $middleware = $routes->getByName($name)?->gatherMiddleware() ?? [];
            $this->assertNotEmpty(array_filter($middleware, static fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, 'throttle:') && str_ends_with($entry, $key)), $name);
        }
    }

    public function test_the_author_edits_a_post_and_its_previous_version_is_kept(): void
    {
        $this->actingAs($this->author)
            ->patchJson("/foro/mensajes/{$this->firstPost->id()->value}", ['document' => $this->text('Corregido')])
            ->assertOk()
            ->assertJsonPath('post.html', '<p>Corregido</p>')
            ->assertJsonPath('post.editedAt', fn (?string $at): bool => $at !== null);

        $revisions = app(PostRevisionRepositoryInterface::class)->ofPost($this->firstPost->id());
        $this->assertCount(1, $revisions);
        $this->assertSame('Primer mensaje', $revisions[0]->body->plainText);
        $this->assertSame(strtolower($this->author->id), $revisions[0]->editorId());
    }

    public function test_nobody_edits_or_deletes_someone_elses_post(): void
    {
        $other = UserModel::factory()->create();
        $this->access->letWrite($other);
        $url = "/foro/mensajes/{$this->firstPost->id()->value}";

        $this->actingAs($other)->patchJson($url, ['document' => $this->text('Mío')])->assertForbidden();
        $this->actingAs($other)->deleteJson($url)->assertForbidden();

        $this->assertSame('Primer mensaje', $this->posts()->findOrFail($this->firstPost->id())->body()->plainText);
    }

    public function test_the_author_deletes_a_post_and_it_keeps_its_place_as_deleted(): void
    {
        $url = "/foro/mensajes/{$this->firstPost->id()->value}";

        $this->actingAs($this->author)->deleteJson($url)
            ->assertOk()
            ->assertJsonPath('post.status', 'deleted')
            ->assertJsonPath('post.number', 1)
            ->assertJsonPath('post.html', null)
            ->assertJsonPath('post.placeholder', 'Mensaje borrado')
            ->assertJsonPath('post.canEdit', false);

        $this->assertSame(PostStatus::Deleted, $this->posts()->findOrFail($this->firstPost->id())->status());

        $this->actingAs($this->author)->deleteJson($url)->assertStatus(409);
        $this->actingAs($this->author)->patchJson($url, ['document' => $this->text('Vuelvo')])->assertStatus(409);
    }

    public function test_moderators_read_every_version_of_a_post_and_nobody_else_does(): void
    {
        $moderator = UserModel::factory()->create(['name' => 'Moderación', 'display_name' => null]);
        $this->access->letModerate($moderator);
        app(ForumWriter::class)->editPost($this->firstPost->id(), self::aBody('Segunda'), strtolower($this->author->id), new DateTimeImmutable('2026-11-10 19:00:00'));
        $url = "/foro/mensajes/{$this->firstPost->id()->value}/versiones";

        $this->actingAs($moderator)->getJson($url)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.number', 1)
            ->assertJsonPath('data.0.html', '<p>Primer mensaje</p>')
            ->assertJsonPath('data.0.isCurrent', false)
            ->assertJsonPath('data.1.html', '<p>Segunda</p>')
            ->assertJsonPath('data.1.authorName', 'Ana Pérez')
            ->assertJsonPath('data.1.isCurrent', true);

        $this->actingAs($this->author)->getJson($url)->assertForbidden();
    }

    public function test_mention_candidates_are_only_people_who_may_read_the_discussion(): void
    {
        $reader = UserModel::factory()->create(['name' => 'Anabel Ruiz', 'display_name' => null]);
        $alsoReader = UserModel::factory()->create(['name' => 'Juana', 'display_name' => 'Luciana']);
        UserModel::factory()->create(['name' => 'Anacleto Sin Acceso', 'display_name' => null]);
        $gone = UserModel::factory()->create(['name' => 'Ana Anónima', 'display_name' => null, 'anonymized_at' => now()]);
        $this->access->letRead($reader, $alsoReader, $gone);
        $url = "/foro/temas/{$this->discussion->id()->value}/menciones";

        $names = $this->actingAs($this->author)->getJson($url.'?q=ana')
            ->assertOk()
            ->json('data.*.name');
        $this->assertIsArray($names);
        // Names that start with the text first (in the database's collation), then those that contain it
        $this->assertCount(3, $names);
        $this->assertEqualsCanonicalizing(['Ana Pérez', 'Anabel Ruiz'], array_slice($names, 0, 2));
        $this->assertSame('Luciana', $names[2]);

        $this->actingAs($this->author)->getJson($url.'?q=')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->author)->getJson($url.'?q=100%25')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs(UserModel::factory()->create())->getJson($url.'?q=ana')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>|string  $document
     * @return TestResponse<Response>
     */
    private function reply(array|string $document): TestResponse
    {
        return $this->actingAs($this->author)->postJson($this->replyUrl(), ['document' => $document]);
    }

    private function replyUrl(): string
    {
        return "/foro/temas/{$this->discussion->id()->value}/mensajes";
    }

    private function posts(): PostRepositoryInterface
    {
        return app(PostRepositoryInterface::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $text): array
    {
        return $this->doc([$this->paragraph([['type' => 'text', 'text' => $text]])]);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function paragraph(array $content): array
    {
        return ['type' => 'paragraph', 'content' => $content];
    }
}
