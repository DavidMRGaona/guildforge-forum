<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What a reference opens: the post as a box, whole when short and without its images and
 * embeds when long; and the posts that reply to one, oldest first, twenty at a time. Whoever
 * may not read a post gets what a missing one gets.
 */
final class PostPreviewEndpointsTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string IMAGE = 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $author;

    private Discussion $discussion;

    private Post $first;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Storage::fake('images');
        $this->author = UserModel::factory()->create();
        [$this->discussion, $this->first] = $this->aDiscussion($this->aCategory(), authorId: $this->author->id);
    }

    public function test_a_visible_post_is_previewed(): void
    {
        $this->getJson($this->previewUrl($this->first))
            ->assertOk()
            ->assertJsonPath('id', $this->first->id()->value)
            ->assertJsonPath('number', 1)
            ->assertJsonPath('authorName', app(AccountDirectoryInterface::class)->namesOf([$this->author->id])[$this->author->id] ?? null)
            ->assertJsonPath('discussionTitle', 'Sesión del viernes')
            ->assertJsonPath('html', '<p>Primer mensaje</p>')
            ->assertJsonPath('truncated', false)
            ->assertJsonPath('url', app(ForumUrlsInterface::class)->discussion($this->numbered(), 1));
    }

    public function test_what_may_not_be_read_answers_like_a_missing_post(): void
    {
        $hidden = $this->reply('Oculto');
        app(ForumWriter::class)->changePost($hidden->id(), static fn (Post $post) => $post->hide());
        $pending = $this->reply('Pendiente', needsApproval: true);
        $deleted = $this->reply('Borrado');
        app(ForumWriter::class)->deletePostByAuthor($deleted->id(), new DateTimeImmutable('2026-11-10 21:00:00'));
        $role = RoleModel::query()->create(['name' => 'keepers', 'display_name' => 'Keepers']);
        [, $private] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::roles([$role->id]), 'privada'));

        $missing = $this->getJson(route('forum.posts.preview', ['post' => '0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a']))->assertNotFound()->json();
        foreach ([$hidden, $pending, $deleted, $private] as $post) {
            $this->assertSame($missing, $this->getJson($this->previewUrl($post))->assertNotFound()->json());
        }
    }

    public function test_a_malformed_id_is_not_found(): void
    {
        $this->getJson('/foro/mensajes/no-uuid/vista')->assertNotFound();
    }

    public function test_a_long_post_is_shown_without_its_images_and_embeds(): void
    {
        $long = $this->replyWith([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('a', 601)]]],
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE, 'alt' => 'Mapa']],
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE, 'alt' => 'Otro']],
            ['type' => 'embed', 'attrs' => ['service' => 'youtube', 'id' => 'dQw4w9WgXcQ']],
        ], str_repeat('a', 601));

        $preview = $this->getJson($this->previewUrl($long))->assertOk();

        $preview->assertJsonPath('truncated', true)->assertJsonPath('hiddenImages', 2)->assertJsonPath('hiddenEmbeds', 1);
        $this->assertStringNotContainsString('<img', (string) $preview->json('html'));
        $this->assertStringNotContainsString('forum-embed', (string) $preview->json('html'));
    }

    public function test_a_short_post_keeps_its_images(): void
    {
        $short = $this->replyWith([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mapa']]],
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE, 'alt' => 'Mapa']],
        ], 'Mapa');

        $preview = $this->getJson($this->previewUrl($short))->assertOk();

        $preview->assertJsonPath('truncated', false)->assertJsonPath('hiddenImages', 0);
        $this->assertStringContainsString('<img', (string) $preview->json('html'));
    }

    public function test_in_character_it_shows_the_character_and_no_title(): void
    {
        $arwen = $this->aCharacterOf($this->author->id);
        $post = app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), $this->author->id, self::aBody('En personaje'), new DateTimeImmutable('2026-11-10 20:00:00'), false, $arwen->id());

        $this->getJson($this->previewUrl($post))
            ->assertOk()
            ->assertJsonPath('character.name', 'Arwen')
            ->assertJsonPath('authorTitle', null);
    }

    public function test_replies_come_oldest_first_twenty_at_a_time(): void
    {
        $replies = [];
        foreach (range(1, 21) as $index) {
            $replies[] = $this->replyWith([['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => "Respuesta {$index} a "],
                ['type' => 'postReference', 'attrs' => ['postId' => $this->first->id()->value]],
            ]]], "Respuesta {$index} a", sprintf('2026-11-11 %02d:00:00', $index));
        }

        $page = $this->getJson(route('forum.posts.replies', ['post' => $this->first->id()->value]))->assertOk();

        $page->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.id', $replies[0]->id()->value)
            ->assertJsonPath('data.19.id', $replies[19]->id()->value)
            ->assertJsonPath('next', route('forum.posts.replies', ['post' => $this->first->id()->value, 'pagina' => 2]));
        $this->getJson((string) $page->json('next'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $replies[20]->id()->value)
            ->assertJsonPath('next', null);
    }

    public function test_replies_the_reader_may_not_read_are_left_out(): void
    {
        $visible = $this->replyWith([['type' => 'paragraph', 'content' => [['type' => 'postReference', 'attrs' => ['postId' => $this->first->id()->value]]]]], '');
        $hidden = $this->replyWith([['type' => 'paragraph', 'content' => [['type' => 'postReference', 'attrs' => ['postId' => $this->first->id()->value]]]]], '');
        app(ForumWriter::class)->changePost($hidden->id(), static fn (Post $post) => $post->hide());

        $this->getJson(route('forum.posts.replies', ['post' => $this->first->id()->value]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id()->value);
    }

    public function test_replies_of_a_post_that_may_not_be_read_are_not_found(): void
    {
        $hidden = $this->reply('Oculto');
        app(ForumWriter::class)->changePost($hidden->id(), static fn (Post $post) => $post->hide());

        $this->getJson(route('forum.posts.replies', ['post' => $hidden->id()->value]))->assertNotFound();
    }

    private function reply(string $text, bool $needsApproval = false): Post
    {
        return app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), null, self::aBody($text), new DateTimeImmutable('2026-11-10 20:00:00'), $needsApproval);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     */
    private function replyWith(array $content, string $plainText, string $at = '2026-11-10 20:00:00'): Post
    {
        return app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), null, new PostBody(['type' => 'doc', 'content' => $content], $plainText), new DateTimeImmutable($at), false);
    }

    private function numbered(): Discussion
    {
        $discussion = app(DiscussionRepositoryInterface::class)->find($this->discussion->id());
        $this->assertInstanceOf(Discussion::class, $discussion);

        return $discussion;
    }

    private function previewUrl(Post $post): string
    {
        return route('forum.posts.preview', ['post' => $post->id()->value]);
    }
}
