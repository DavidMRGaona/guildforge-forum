<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Tests\Support\FakeForumAccess;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Images for posts go to the forum's folder, as JPEG, PNG, WebP or GIF read from the bytes.
 */
final class PostImageUploadTest extends ModuleTestCase
{
    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private FakeForumAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('images');
        $this->access = new FakeForumAccess;
        $this->app->instance(ForumAccessInterface::class, $this->access);
    }

    private function writer(): UserModel
    {
        $user = UserModel::factory()->create();
        $this->access->letWrite($user);

        return $user;
    }

    public function test_an_image_is_stored_in_the_forum_folder_and_its_path_fits_the_image_node(): void
    {
        $response = $this->actingAs($this->writer())
            ->post('/foro/imagenes', ['image' => UploadedFile::fake()->image('mapa.png', 40, 30)], ['Accept' => 'application/json'])
            ->assertCreated();

        $path = $response->json('path');
        $this->assertIsString($path);
        $this->assertMatchesRegularExpression(ImageNode::PATH_PATTERN, $path);
        $this->assertStringEndsWith('.png', $path);
        Storage::disk('images')->assertExists($path);
        $this->assertIsString($response->json('url'));
    }

    public function test_the_extension_comes_from_the_bytes_not_from_the_name(): void
    {
        $gif = UploadedFile::fake()->image('disfraz.gif', 10, 10);
        $renamed = UploadedFile::fake()->createWithContent('disfraz.png', (string) file_get_contents($gif->getPathname()));

        $path = $this->actingAs($this->writer())
            ->post('/foro/imagenes', ['image' => $renamed], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('path');

        $this->assertIsString($path);
        $this->assertStringEndsWith('.gif', $path);
    }

    public function test_svg_files_and_files_that_are_not_images_are_refused(): void
    {
        $user = $this->writer();
        $files = [
            UploadedFile::fake()->createWithContent('dibujo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('falsa.png', '<?php echo 1;'),
            UploadedFile::fake()->create('pesada.png', 5000, 'image/png'),
        ];

        foreach ($files as $file) {
            $this->actingAs($user)
                ->post('/foro/imagenes', ['image' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('image');
        }

        $this->assertSame([], Storage::disk('images')->allFiles());
    }

    public function test_guests_and_people_who_cannot_write_anywhere_cannot_upload(): void
    {
        $this->post('/foro/imagenes', ['image' => UploadedFile::fake()->image('mapa.png')], ['Accept' => 'application/json'])
            ->assertUnauthorized();

        $this->actingAs(UserModel::factory()->create())
            ->post('/foro/imagenes', ['image' => UploadedFile::fake()->image('mapa.png')], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame([], Storage::disk('images')->allFiles());
    }
}
