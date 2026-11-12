<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Media\Services\MediaFileIdResolverInterface;
use App\Domain\Media\ValueObjects\MediaFileId;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\RemoteFetcherInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\RemoteFetchException;
use Modules\Forum\Domain\Repositories\EmbedCardRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Http\Presenters\EditorProps;
use Modules\Forum\Infrastructure\Media\PostImageMediaSource;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeForumAccess;
use Modules\Forum\Tests\Support\FakeRemoteFetcher;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Posts of other services: the server reads the link and fetches the card once from the
 * service's oEmbed data; posts keep only the service and the id, and readers get the forum's
 * card with the player held back until they ask for it.
 */
final class EmbedEndpointsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string VIDEO = 'dQw4w9WgXcQ';

    private const string OEMBED = 'https://www.youtube.com/oembed?format=json&url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3DdQw4w9WgXcQ';

    private const string THUMBNAIL = 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private FakeRemoteFetcher $fetcher;

    private FakeForumAccess $access;

    private UserModel $author;

    private Discussion $discussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Storage::fake('images');
        $this->fetcher = new FakeRemoteFetcher;
        $this->app->instance(RemoteFetcherInterface::class, $this->fetcher);
        $this->access = new FakeForumAccess;
        $this->app->instance(ForumAccessInterface::class, $this->access);
        config(['app.url' => 'https://foro.example.org']);

        $this->author = UserModel::factory()->create();
        $this->access->letWrite($this->author);
        [$this->discussion] = $this->aDiscussion($this->aCategory(), authorId: $this->author->id);
    }

    public function test_a_link_gives_the_post_and_its_card_with_plain_texts_and_a_thumbnail_copy(): void
    {
        $this->fetcher
            ->answer(self::OEMBED, (string) json_encode([
                'title' => "  Partida <b>épica</b>\nde rol ",
                'author_name' => 'Canal rolero',
                'html' => '<iframe src="https://www.youtube.com/embed/x"></iframe><script>alert(1)</script>',
                'thumbnail_url' => self::THUMBNAIL,
            ]))
            ->answer(self::THUMBNAIL, $this->jpeg());

        $response = $this->embed('https://youtu.be/'.self::VIDEO.'?si=abc')->assertCreated();

        $response->assertJsonPath('service', 'youtube')
            ->assertJsonPath('id', self::VIDEO)
            ->assertJsonPath('label', 'YouTube')
            ->assertJsonPath('url', 'https://www.youtube.com/watch?v='.self::VIDEO)
            ->assertJsonPath('card.available', true)
            // Kept as plain text in one line: shown escaped, never as markup
            ->assertJsonPath('card.title', 'Partida <b>épica</b> de rol')
            ->assertJsonPath('card.authorName', 'Canal rolero');
        $this->assertIsString($response->json('card.thumbnailUrl'));

        $card = app(EmbedCardRepositoryInterface::class)->find('youtube', self::VIDEO);
        $this->assertNotNull($card);
        $this->assertMatchesRegularExpression(ImageNode::EXTERNAL_PATH_PATTERN, (string) $card->thumbnailPath);
    }

    public function test_the_card_is_fetched_once(): void
    {
        $this->fetcher->answer(self::OEMBED, (string) json_encode(['title' => 'Vídeo']));

        $this->embed('https://www.youtube.com/watch?v='.self::VIDEO)->assertCreated();
        $this->embed('https://youtube.com/shorts/'.self::VIDEO)->assertCreated();

        $this->assertCount(1, $this->fetcher->fetched);
    }

    public function test_a_thumbnail_off_the_service_hosts_is_never_fetched(): void
    {
        $this->fetcher->answer(self::OEMBED, (string) json_encode(['title' => 'Vídeo', 'thumbnail_url' => 'https://tracker.example.com/pixel.jpg']));

        $this->embed('https://youtu.be/'.self::VIDEO)->assertCreated()->assertJsonPath('card.thumbnailUrl', null);

        $this->assertSame([self::OEMBED], array_column($this->fetcher->fetched, 'url'));
    }

    public function test_a_post_the_service_does_not_give_keeps_a_card_with_its_link_and_is_tried_again_a_day_later(): void
    {
        $this->fetcher->answer(self::OEMBED, RemoteFetchException::because(RemoteFetchException::HTTP_STATUS, 'Not found'));

        $this->embed('https://youtu.be/'.self::VIDEO)->assertCreated()->assertJsonPath('card.available', false);
        $this->embed('https://youtu.be/'.self::VIDEO)->assertCreated();
        $this->assertCount(1, $this->fetcher->fetched);

        $this->travelTo(CarbonImmutable::now()->addHours(25));
        $this->fetcher->answer(self::OEMBED, (string) json_encode(['title' => 'Ya está']));
        $this->embed('https://youtu.be/'.self::VIDEO)->assertCreated()->assertJsonPath('card.title', 'Ya está');
    }

    public function test_services_without_oembed_give_a_card_without_fetching(): void
    {
        $this->embed('https://www.instagram.com/p/C1a2B3c4D5e/')->assertCreated()
            ->assertJsonPath('service', 'instagram')
            ->assertJsonPath('card.available', true)
            ->assertJsonPath('card.title', null);

        $this->assertSame([], $this->fetcher->fetched);
    }

    public function test_a_post_of_x_shows_its_text_from_the_oembed_markup_without_tags(): void
    {
        $this->fetcher->answer(
            'https://publish.twitter.com/oembed?omit_script=1&dnt=true&url=https%3A%2F%2Ftwitter.com%2Fi%2Fstatus%2F1790000000000000000',
            (string) json_encode(['author_name' => 'Rolero', 'html' => '<blockquote><p lang="es">Esta noche <a href="https://t.co/x">partida</a> &amp; dados</p>&mdash; Rolero</blockquote>']),
        );

        $this->embed('https://x.com/rolero/status/1790000000000000000')->assertCreated()
            ->assertJsonPath('card.title', 'Esta noche partida & dados')
            ->assertJsonPath('card.authorName', 'Rolero');
    }

    public function test_links_that_are_not_posts_of_a_service_on_are_refused(): void
    {
        config(['modules.settings.forum.embed_services' => ['vimeo']]);

        $this->embed('https://youtu.be/'.self::VIDEO)->assertUnprocessable()->assertJsonPath('reason', 'unknown_embed');
        $this->embed('https://www.youtube.com/@rolero')->assertUnprocessable()->assertJsonPath('reason', 'unknown_embed');
        $this->embed('http://vimeo.com/76979871')->assertUnprocessable()->assertJsonPath('reason', 'unknown_embed');
        $this->embed('no es un enlace')->assertUnprocessable()->assertJsonPath('reason', 'unknown_embed');

        $this->assertSame([], $this->fetcher->fetched);
    }

    public function test_a_published_embed_shows_the_forum_card_and_holds_the_player_back(): void
    {
        $this->fetcher->answer(self::OEMBED, (string) json_encode(['title' => '<script>alert(1)</script>Vídeo', 'author_name' => 'Canal & "amigos"']));
        $this->embed('https://youtu.be/'.self::VIDEO);
        config(['modules.settings.forum.embed_cookie_categories.youtube' => 'videos']);

        $html = (string) $this->reply($this->author, $this->withEmbed('youtube', self::VIDEO))->assertCreated()->json('post.html');

        $this->assertStringContainsString('data-forum-embed data-embed-service="youtube"', $html);
        $this->assertStringContainsString('data-embed-src="https://www.youtube-nocookie.com/embed/'.self::VIDEO.'?rel=0"', $html);
        $this->assertStringContainsString('data-embed-consent="videos"', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Vídeo', $html);
        $this->assertStringContainsString('Canal &amp; &quot;amigos&quot;', $html);
        $this->assertStringContainsString('<button type="button" class="forum-embed__load" data-embed-load>Cargar contenido de YouTube</button>', $html);
        $this->assertStringContainsString('href="https://www.youtube.com/watch?v='.self::VIDEO.'"', $html);
        // Nothing of the service is loaded by the page itself
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_embeds_of_a_service_turned_off_are_refused_new_and_kept_on_edit_without_a_player(): void
    {
        $number = (int) $this->reply($this->author, $this->withEmbed('youtube', self::VIDEO))->assertCreated()->json('post.number');
        $post = $this->postNumbered($number);
        config(['modules.settings.forum.embed_services' => ['vimeo']]);

        $this->reply($this->author, $this->withEmbed('youtube', 'abcdefghijk'))->assertUnprocessable()->assertJsonPath('reason', 'embed_disabled');

        $html = (string) $this->actingAs($this->author)
            ->patchJson("/foro/mensajes/{$post->id()->value}", ['document' => $this->withEmbed('youtube', self::VIDEO, 'Editado')])
            ->assertOk()
            ->json('post.html');
        $this->assertStringContainsString('Ver en YouTube', $html);
        $this->assertStringNotContainsString('data-embed-src', $html);
        $this->assertStringNotContainsString('data-embed-load', $html);
    }

    public function test_forged_embeds_are_refused(): void
    {
        $this->reply($this->author, $this->withEmbed('myspace', 'abc'))->assertUnprocessable()->assertJsonPath('reason', 'invalid_embed');
        $this->reply($this->author, $this->withEmbed('youtube', '"><script>'))->assertUnprocessable()->assertJsonPath('reason', 'invalid_embed');
        $this->reply($this->author, ['type' => 'doc', 'content' => [['type' => 'embed', 'attrs' => ['service' => 'youtube', 'id' => self::VIDEO, 'html' => '<iframe>']]]])
            ->assertUnprocessable()->assertJsonPath('reason', 'invalid_attribute');
    }

    public function test_the_thumbnail_counts_as_used_while_a_post_embeds_its_post(): void
    {
        $this->fetcher
            ->answer(self::OEMBED, (string) json_encode(['title' => 'Vídeo', 'thumbnail_url' => self::THUMBNAIL]))
            ->answer(self::THUMBNAIL, $this->jpeg());
        $this->embed('https://youtu.be/'.self::VIDEO);
        $path = (string) app(EmbedCardRepositoryInterface::class)->find('youtube', self::VIDEO)?->thumbnailPath;
        $file = app(MediaFileIdResolverInterface::class)->fromStoredPath($path);
        $this->assertInstanceOf(MediaFileId::class, $file);
        $source = app(PostImageMediaSource::class);
        $this->assertFalse($source->usesFile($file));

        $number = (int) $this->reply($this->author, $this->withEmbed('youtube', self::VIDEO))->assertCreated()->json('post.number');

        $this->assertTrue($source->usesFile($file));
        $this->assertSame([$path], array_map(static fn ($usage): string => $usage->storedPath, $source->usagesFor($this->postNumbered($number)->id()->value)));
    }

    public function test_the_editor_learns_the_services_on(): void
    {
        config(['modules.settings.forum.embed_services' => ['youtube', 'bluesky']]);
        $props = app(EditorProps::class);

        $this->assertSame(route('forum.embeds.store'), $props->for($this->author)['embedUrl']);
        $this->assertSame([
            ['key' => 'bluesky', 'label' => 'Bluesky', 'hosts' => ['bsky.app']],
            ['key' => 'youtube', 'label' => 'YouTube', 'hosts' => ['youtube.com', 'youtu.be']],
        ], $props->for($this->author)['embedServices']);
        $this->assertNull($props->for(null)['embedUrl']);

        config(['modules.settings.forum.embed_services' => []]);
        $this->assertNull($props->for($this->author)['embedUrl']);
    }

    /**
     * @return TestResponse<Response>
     */
    private function embed(string $url): TestResponse
    {
        return $this->actingAs($this->author)->postJson('/foro/incrustaciones', ['url' => $url]);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return TestResponse<Response>
     */
    private function reply(UserModel $author, array $document): TestResponse
    {
        return $this->actingAs($author)->postJson("/foro/temas/{$this->discussion->id()->value}/mensajes", ['document' => $document]);
    }

    /**
     * @return array<string, mixed>
     */
    private function withEmbed(string $service, string $id, string $text = 'Mirad'): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
            ['type' => 'embed', 'attrs' => ['service' => $service, 'id' => $id]],
        ]];
    }

    private function postNumbered(int $number): Post
    {
        foreach (app(PostRepositoryInterface::class)->inDiscussion($this->discussion->id()) as $post) {
            if ($post->number()?->value === $number) {
                return $post;
            }
        }

        $this->fail("No post {$number}");
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(16, 9);
        $this->assertInstanceOf(GdImage::class, $image);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
