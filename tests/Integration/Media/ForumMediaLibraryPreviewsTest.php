<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Media;

use App\Application\Media\Contracts\ConcealsUnusedMediaFiles;
use App\Application\Media\Contracts\ReleasesMediaThroughCoreInterface;
use App\Application\Media\Contracts\ShowsMediaPreviews;
use App\Application\Media\Services\MediaCatalogInterface;
use App\Application\Media\Services\MediaFileIdResolverInterface;
use App\Application\Media\Services\MediaIndexerInterface;
use App\Application\Media\Services\MediaSourceRegistryInterface;
use App\Domain\Media\ValueObjects\MediaFileId;
use App\Infrastructure\Media\Persistence\Eloquent\Models\MediaFileModel;
use App\Infrastructure\Media\Services\MediaLibraryQueryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Media\CharacterPortraitMediaSource;
use Modules\Forum\Infrastructure\Media\PostImageMediaSource;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Media\InMemoryMediaCatalog;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What the media library shows of the forum, through the library's own rule after a sync
 * (forum#157): the images of what every member reads, and nothing else. Hidden usages are
 * concealed (core#837), and so is every unused file of the forum's folders (core#843): the images
 * of a deleted discussion, of a purged post or never posted, and replaced portraits.
 */
final class ForumMediaLibraryPreviewsTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MAP = 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp';

    private const string SECRET = 'forum/posts/2026/11/1c6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5b.png';

    private const string NEVER_POSTED = 'forum/posts/2026/11/2d6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5c.jpg';

    private const string COPY = 'forum/external/2026/11/3e6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5d.png';

    private const string PORTRAIT = 'forum/characters/2026/11/4f6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5e.webp';

    private const string OLD_PORTRAIT = 'forum/characters/2026/11/5a6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5f.webp';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private InMemoryMediaCatalog $catalog;

    private UserModel $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->moderator = $this->anAccount('forum:forum.moderate');
        $this->catalog = new InMemoryMediaCatalog;
        $this->app->instance(MediaCatalogInterface::class, $this->catalog);
    }

    public function test_both_sources_show_their_previews_and_conceal_their_unused_files(): void
    {
        $registry = app(MediaSourceRegistryInterface::class);

        foreach ([PostImageMediaSource::TYPE, CharacterPortraitMediaSource::TYPE] as $type) {
            $source = $registry->source($type);
            $this->assertInstanceOf(ShowsMediaPreviews::class, $source, $type);
            $this->assertInstanceOf(ConcealsUnusedMediaFiles::class, $source, $type);
            // «Elegir de la biblioteca» stays off: the forum releases its files itself
            $this->assertNotInstanceOf(ReleasesMediaThroughCoreInterface::class, $source, $type);
        }
    }

    public function test_the_image_of_a_post_every_member_reads_is_shown(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::everyone(), 'general'));
        $this->replyWith($discussion, [self::MAP]);

        $this->sync([self::MAP]);

        $this->assertFalse($this->library()->conceals($this->file(self::MAP)));
    }

    public function test_the_image_of_a_hidden_post_or_of_a_private_category_is_concealed(): void
    {
        [$public] = $this->aDiscussion($this->aCategory());
        $hidden = $this->replyWith($public, [self::MAP]);
        app(ForumModeration::class)->hidePost($this->moderator, $hidden->id(), 'Destripe');
        [$private] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::associationMembers(), 'socios'), title: 'Plan secreto');
        $this->replyWith($private, [self::SECRET]);

        $this->sync([self::MAP, self::SECRET]);

        $this->assertTrue($this->library()->conceals($this->file(self::MAP)));
        $this->assertTrue($this->library()->conceals($this->file(self::SECRET)));
    }

    public function test_unused_files_of_every_forum_folder_are_concealed_and_never_offered_for_reuse(): void
    {
        // A discussion deleted from a members' category leaves its image without any usage
        [$private] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::associationMembers(), 'socios'), title: 'Plan secreto');
        $this->replyWith($private, [self::SECRET]);
        app(ForumWriter::class)->deleteDiscussion($private->id());
        // A portrait replaced by another is left behind
        $character = $this->aPortraitedCharacter('Villano secreto', self::OLD_PORTRAIT);
        $character->changePortrait(self::PORTRAIT, new DateTimeImmutable);
        app(CharacterRepositoryInterface::class)->save($character);

        $unused = [self::SECRET, self::NEVER_POSTED, self::COPY, self::OLD_PORTRAIT];
        $this->sync($unused);

        $library = $this->library();
        $reusable = $library->applyReusable($library->query())->pluck('file_id')->all();
        foreach ($unused as $path) {
            $file = $this->file($path);
            $this->assertSame(0, $file->usages()->count(), $path);
            $this->assertTrue($library->conceals($file), $path);
            $this->assertNotContains($file->file_id, $reusable, $path);
        }
    }

    public function test_the_portrait_of_a_character_every_member_sees_is_shown(): void
    {
        $character = $this->aPortraitedCharacter('Arwen', self::PORTRAIT);
        [$discussion] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::everyone(), 'general'));
        app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), $character->ownerId(), self::aBody('En personaje'), new DateTimeImmutable('2026-11-12 11:00:00'), false, $character->id());

        $this->sync([self::PORTRAIT]);

        $this->assertFalse($this->library()->conceals($this->file(self::PORTRAIT)));
    }

    public function test_the_portrait_of_a_character_nobody_has_seen_yet_is_concealed(): void
    {
        $this->aPortraitedCharacter('Nadie', self::PORTRAIT);

        $this->sync([self::PORTRAIT]);

        $this->assertTrue($this->library()->conceals($this->file(self::PORTRAIT)));
    }

    /**
     * Puts the files in the catalog and syncs the whole library, as `media:sync` does.
     *
     * @param  list<string>  $paths
     */
    private function sync(array $paths): void
    {
        foreach ($paths as $path) {
            $this->catalog->add($this->fileId($path)->value, uploadedAt: '2026-10-01 10:00:00');
        }

        $result = app(MediaIndexerInterface::class)->sync();

        $this->assertSame([], $result->failedSources);
    }

    private function file(string $path): MediaFileModel
    {
        $file = MediaFileModel::query()->where('file_id', $this->fileId($path)->value)->first();
        $this->assertInstanceOf(MediaFileModel::class, $file, $path);

        return $file;
    }

    private function fileId(string $path): MediaFileId
    {
        $id = app(MediaFileIdResolverInterface::class)->fromStoredPath($path);
        $this->assertInstanceOf(MediaFileId::class, $id);

        return $id;
    }

    private function library(): MediaLibraryQueryInterface
    {
        return app(MediaLibraryQueryInterface::class);
    }

    private function aPortraitedCharacter(string $name, string $portrait): Character
    {
        $character = $this->aCharacterOf(UserModel::factory()->create()->id, $name);
        $character->changePortrait($portrait, new DateTimeImmutable);
        app(CharacterRepositoryInterface::class)->save($character);

        return $character;
    }

    /**
     * @param  list<string>  $paths
     */
    private function replyWith(Discussion $discussion, array $paths): Post
    {
        return app(ForumWriter::class)->reply(
            $discussion->id(),
            PostId::generate(),
            null,
            new PostBody([
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mirad']]],
                    ...array_map(static fn (string $path): array => ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => $path]], $paths),
                ],
            ], 'Mirad'),
            new DateTimeImmutable('2026-11-10 18:30:00'),
            false,
        );
    }
}
