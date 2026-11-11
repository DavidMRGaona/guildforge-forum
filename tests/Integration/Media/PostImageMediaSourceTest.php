<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Media;

use App\Application\Media\Services\MediaFileIdResolverInterface;
use App\Application\Media\Services\MediaSourceRegistryInterface;
use App\Domain\Media\ValueObjects\MediaFileId;
use DateTimeImmutable;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Infrastructure\Media\PostImageMediaSource;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * An image counts as used while any version of a post shows it, whatever the post's status.
 */
final class PostImageMediaSourceTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MAP = 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp';

    private const string PORTRAIT = 'forum/posts/2026/11/1c6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5b.png';

    private const string UNUSED = 'forum/posts/2026/11/2d6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5c.jpg';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
    }

    public function test_the_module_registers_the_source_for_its_folder(): void
    {
        $source = app(MediaSourceRegistryInterface::class)->source(PostImageMediaSource::TYPE);

        $this->assertInstanceOf(PostImageMediaSource::class, $source);
        $this->assertSame(['forum/posts/'], $source->folders());
        $this->assertFalse($source->hidden());
    }

    public function test_images_of_the_current_body_and_of_earlier_versions_are_used_once_each(): void
    {
        [$discussion, $first] = $this->aDiscussion($this->aCategory());
        $reply = $this->aReply($discussion, '2026-11-10 18:30:00');
        $this->writer()->editPost($reply->id(), $this->withImages([self::MAP, self::PORTRAIT]), null, new DateTimeImmutable('2026-11-10 18:40:00'));
        // The map leaves the post but stays in its history
        $this->writer()->editPost($reply->id(), $this->withImages([self::PORTRAIT]), null, new DateTimeImmutable('2026-11-10 18:50:00'));
        $this->writer()->deletePostByAuthor($reply->id(), new DateTimeImmutable('2026-11-10 19:30:00'));

        $usages = iterator_to_array($this->source()->usages(), false);

        $this->assertEqualsCanonicalizing([self::MAP, self::PORTRAIT], array_map(static fn ($usage): string => $usage->storedPath, $usages));
        $this->assertSame([$reply->id()->value], array_values(array_unique(array_map(static fn ($usage): string => $usage->recordId, $usages))));
        $this->assertSame('Sesión del viernes, mensaje 2', $usages[0]->title);
        $this->assertSame('Imagen del mensaje', $usages[0]->role);
        $this->assertCount(2, $this->source()->usagesFor($reply->id()->value));
        $this->assertSame([], $this->source()->usagesFor($first->id()->value));
        $this->assertSame([], $this->source()->usagesFor('not-a-uuid'));

        $this->assertTrue($this->source()->usesFile($this->fileId(self::MAP)));
        $this->assertTrue($this->source()->usesFile($this->fileId(self::PORTRAIT)));
        $this->assertFalse($this->source()->usesFile($this->fileId(self::UNUSED)));
    }

    public function test_deleting_the_discussion_frees_its_images(): void
    {
        [$discussion, $post] = $this->aDiscussion($this->aCategory());
        $this->writer()->editPost($post->id(), $this->withImages([self::MAP]), null, new DateTimeImmutable('2026-11-10 18:40:00'));
        $this->assertTrue($this->source()->usesFile($this->fileId(self::MAP)));

        $this->writer()->deleteDiscussion($discussion->id());

        $this->assertFalse($this->source()->usesFile($this->fileId(self::MAP)));
        $this->assertSame([], iterator_to_array($this->source()->usages(), false));
    }

    private function source(): PostImageMediaSource
    {
        return app(PostImageMediaSource::class);
    }

    private function writer(): ForumWriter
    {
        return app(ForumWriter::class);
    }

    private function fileId(string $path): MediaFileId
    {
        $id = app(MediaFileIdResolverInterface::class)->fromStoredPath($path);
        $this->assertInstanceOf(MediaFileId::class, $id);

        return $id;
    }

    /**
     * @param  list<string>  $paths
     */
    private function withImages(array $paths): PostBody
    {
        return new PostBody([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mirad']]],
                ...array_map(static fn (string $path): array => ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => $path]], $paths),
            ],
        ], 'Mirad');
    }
}
