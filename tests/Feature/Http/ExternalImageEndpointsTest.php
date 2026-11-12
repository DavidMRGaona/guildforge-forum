<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\RemoteFetcherInterface;
use Modules\Forum\Application\Services\ExternalImageRules;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\RemoteFetchException;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Http\Presenters\EditorProps;
use Modules\Forum\Http\Requests\StorePostImageRequest;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeForumAccess;
use Modules\Forum\Tests\Support\FakeRemoteFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Images from other sites: the server makes the forum's copy (the reader's browser never asks
 * the other site), only from the sites of the settings and with its own permission, and a post
 * may only name the copy of the address it shows.
 */
final class ExternalImageEndpointsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string REMOTE = 'https://i.imgur.com/AbC123.png';

    private const string SECRET = 'GPS-SECRET-40.4168N';

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
        config(['modules.settings.forum.external_image_hosts' => ['imgur.com']]);

        $this->author = $this->anAccount(ExternalImageRules::PERMISSION);
        $this->access->letWrite($this->author);
        [$this->discussion] = $this->aDiscussion($this->aCategory(), authorId: $this->author->id);
    }

    public function test_the_server_makes_a_copy_without_metadata_and_answers_what_the_node_keeps(): void
    {
        $this->fetcher->answer(self::REMOTE, $this->pngWithText());

        $response = $this->import(self::REMOTE)->assertCreated();

        $path = $response->json('path');
        $this->assertIsString($path);
        $this->assertMatchesRegularExpression(ImageNode::EXTERNAL_PATH_PATTERN, $path);
        $response->assertJsonPath('url', self::REMOTE);
        $this->assertIsString($response->json('previewUrl'));
        Storage::disk('images')->assertExists($path);
        $this->assertStringNotContainsString(self::SECRET, (string) Storage::disk('images')->get($path));
        // Fetched no heavier than uploads may be
        $this->assertSame(4096 * 1024, $this->fetcher->fetched[0]['maxBytes']);
    }

    public function test_an_address_is_fetched_once_and_its_copy_shared(): void
    {
        $this->fetcher->answer(self::REMOTE, $this->pngWithText());

        $first = $this->import(self::REMOTE)->assertCreated()->json('path');
        $second = $this->import(self::REMOTE)->assertCreated()->json('path');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->fetcher->fetched);
    }

    public function test_only_people_with_the_permission_may_ask_for_a_copy(): void
    {
        $this->actingAs($this->anAccount(StorePostImageRequest::PERMISSION))
            ->postJson('/foro/imagenes/externas', ['url' => self::REMOTE])
            ->assertForbidden();

        $this->assertSame([], $this->fetcher->fetched);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesNotAllowed(): iterable
    {
        yield 'a site not in the settings' => ['https://tracker.example.com/pixel.gif'];
        yield 'a name ending like the site' => ['https://notimgur.com/a.png'];
        yield 'plain http' => ['http://i.imgur.com/a.png'];
        yield 'an address' => ['https://127.0.0.1/a.png'];
        yield 'credentials' => ['https://user@i.imgur.com/a.png'];
        yield 'not an address' => ['imgur'];
    }

    #[DataProvider('addressesNotAllowed')]
    public function test_addresses_off_the_list_are_never_fetched(string $address): void
    {
        $this->import($address)->assertUnprocessable()->assertJsonPath('reason', 'external_image_host');

        $this->assertSame([], $this->fetcher->fetched);
    }

    public function test_an_empty_site_list_turns_them_off(): void
    {
        config(['modules.settings.forum.external_image_hosts' => []]);

        $this->import(self::REMOTE)->assertUnprocessable()->assertJsonPath('reason', 'external_image_host');
    }

    public function test_what_cannot_be_fetched_or_is_not_an_image_is_refused_and_not_stored(): void
    {
        $this->fetcher
            ->answer('https://i.imgur.com/gone.png', RemoteFetchException::because(RemoteFetchException::NOT_PUBLIC, 'x'))
            ->answer('https://i.imgur.com/huge.png', RemoteFetchException::because(RemoteFetchException::TOO_LARGE, 'x'))
            ->answer('https://i.imgur.com/page', '<!doctype html><title>imgur</title>')
            ->answer('https://i.imgur.com/vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->import('https://i.imgur.com/gone.png')->assertUnprocessable()->assertJsonPath('reason', 'external_image_unavailable');
        $this->import('https://i.imgur.com/huge.png')->assertUnprocessable()->assertJsonPath('reason', 'external_image_too_large');
        $this->import('https://i.imgur.com/page')->assertUnprocessable()->assertJsonPath('reason', 'invalid_image');
        $this->import('https://i.imgur.com/vector.svg')->assertUnprocessable()->assertJsonPath('reason', 'invalid_image');

        $this->assertSame([], Storage::disk('images')->allFiles());
    }

    public function test_a_format_the_settings_do_not_allow_is_refused(): void
    {
        config(['modules.settings.forum.image_types' => ['jpeg']]);
        $this->fetcher->answer(self::REMOTE, $this->pngWithText());

        $this->import(self::REMOTE)->assertUnprocessable()->assertJsonPath('reason', 'invalid_image');
    }

    public function test_a_post_shows_the_copy_and_links_to_the_original(): void
    {
        $this->fetcher->answer(self::REMOTE, $this->pngWithText());
        $path = (string) $this->import(self::REMOTE)->json('path');

        $html = (string) $this->reply($this->author, $this->withImage(self::REMOTE, $path))->assertCreated()->json('post.html');

        $this->assertStringContainsString('<img src="'.e((string) Storage::disk('images')->url($path)).'"', $html);
        $this->assertStringNotContainsString('<img src="'.self::REMOTE, $html);
        $this->assertStringContainsString('<a href="'.self::REMOTE.'" target="_blank" rel="nofollow ugc noopener noreferrer" referrerpolicy="no-referrer">i.imgur.com</a>', $html);
    }

    public function test_a_post_naming_another_file_than_the_copy_is_refused(): void
    {
        $this->fetcher->answer(self::REMOTE, $this->pngWithText());
        $this->import(self::REMOTE);
        $forged = 'forum/external/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.png';

        $this->reply($this->author, $this->withImage(self::REMOTE, $forged))->assertUnprocessable()->assertJsonPath('reason', 'invalid_image');
        // An address the forum never copied
        $this->reply($this->author, $this->withImage('https://i.imgur.com/Other.png', $forged))->assertUnprocessable()->assertJsonPath('reason', 'invalid_image');
    }

    public function test_publishing_one_needs_the_permission_and_a_site_still_allowed(): void
    {
        $this->fetcher->answer(self::REMOTE, $this->pngWithText());
        $path = (string) $this->import(self::REMOTE)->json('path');

        $other = UserModel::factory()->create();
        $this->access->letWrite($other);
        $this->reply($other, $this->withImage(self::REMOTE, $path))->assertUnprocessable()->assertJsonPath('reason', 'external_image_not_allowed');

        config(['modules.settings.forum.external_image_hosts' => ['postimg.cc']]);
        $this->reply($this->author, $this->withImage(self::REMOTE, $path))->assertUnprocessable()->assertJsonPath('reason', 'external_image_host');
    }

    public function test_editing_keeps_the_images_a_post_already_had_without_checking_them_again(): void
    {
        $this->fetcher->answer(self::REMOTE, $this->pngWithText());
        $path = (string) $this->import(self::REMOTE)->json('path');
        $number = $this->reply($this->author, $this->withImage(self::REMOTE, $path))->assertCreated()->json('post.number');
        $post = $this->postNumbered((int) $number);

        // The permission is gone and the site left the list: the image stays, nothing new comes in
        $this->author->roles()->detach();
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($this->author);
        config(['modules.settings.forum.external_image_hosts' => []]);

        $this->actingAs($this->author)
            ->patchJson("/foro/mensajes/{$post->id()->value}", ['document' => $this->withImage(self::REMOTE, $path, 'Con texto nuevo')])
            ->assertOk();

        $this->fetcher->answer('https://i.imgur.com/Next.png', $this->pngWithText());
        $this->actingAs($this->author)
            ->patchJson("/foro/mensajes/{$post->id()->value}", ['document' => $this->withImage('https://i.imgur.com/Next.png', $path)])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'external_image_not_allowed');
    }

    public function test_the_editor_learns_where_to_ask_for_copies_only_with_the_permission(): void
    {
        $props = app(EditorProps::class);

        $this->assertSame(route('forum.images.external'), $props->for($this->author)['externalImageUrl']);
        $this->assertSame(['imgur.com'], $props->for($this->author)['externalImageHosts']);
        $this->assertNull($props->for(UserModel::factory()->create())['externalImageUrl']);
        $this->assertNull($props->for(null)['externalImageUrl']);
        $this->assertSame([], $props->for(null)['externalImageHosts']);

        config(['modules.settings.forum.external_image_hosts' => []]);
        $this->assertNull($props->for($this->author)['externalImageUrl']);
    }

    /**
     * @return TestResponse<Response>
     */
    private function import(string $url): TestResponse
    {
        return $this->actingAs($this->author)->postJson('/foro/imagenes/externas', ['url' => $url]);
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
    private function withImage(string $url, string $path, string $text = 'Mirad'): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
            ['type' => 'image', 'attrs' => ['origin' => 'external', 'src' => $url, 'path' => $path, 'alt' => 'Mapa']],
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

    /**
     * A PNG with a text chunk that gives away a place.
     */
    private function pngWithText(): string
    {
        $image = imagecreatetruecolor(12, 8);
        $this->assertInstanceOf(GdImage::class, $image);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $chunk = pack('N', strlen("Comment\0".self::SECRET)).'tEXt'."Comment\0".self::SECRET.pack('N', crc32('tEXt'."Comment\0".self::SECRET));
        $at = (int) strrpos($png, 'IEND') - 4;

        return substr($png, 0, $at).$chunk.substr($png, $at);
    }
}
