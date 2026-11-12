<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Media;

use App\Application\Media\Services\MediaFileIdResolverInterface;
use App\Application\Media\Services\MediaSourceRegistryInterface;
use App\Domain\Media\ValueObjects\MediaFileId;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Infrastructure\Media\CharacterPortraitMediaSource;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * A portrait counts as used while a character, retired or not, shows it; the media library
 * finds the ones replaced or left by a deleted character unused.
 */
final class CharacterPortraitMediaSourceTest extends ModuleTestCase
{
    use BuildsCharacters;
    use UsesUtcStorage;

    private const string PORTRAIT = 'forum/characters/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
    }

    public function test_the_module_registers_the_source_for_its_folder(): void
    {
        $source = app(MediaSourceRegistryInterface::class)->source(CharacterPortraitMediaSource::TYPE);

        $this->assertInstanceOf(CharacterPortraitMediaSource::class, $source);
        $this->assertSame(['forum/characters/'], $source->folders());
        $this->assertSame([], $source->excludedSubfolders());
    }

    public function test_a_portrait_is_used_while_a_character_shows_it_retired_or_not(): void
    {
        $character = $this->aCharacterOf(UserModel::factory()->create()->id, 'Arwen', retired: true);
        $character->changePortrait(self::PORTRAIT, new DateTimeImmutable);
        app(CharacterRepositoryInterface::class)->save($character);

        $usages = iterator_to_array($this->source()->usages(), false);
        $this->assertCount(1, $usages);
        $this->assertSame(self::PORTRAIT, $usages[0]->storedPath);
        $this->assertSame('Personaje Arwen', $usages[0]->title);
        $this->assertSame('Retrato', $usages[0]->role);
        $this->assertTrue($this->source()->usesFile($this->fileOf(self::PORTRAIT)));

        app(CharacterRepositoryInterface::class)->delete($character->id());
        $this->assertFalse($this->source()->usesFile($this->fileOf(self::PORTRAIT)));
    }

    private function fileOf(string $path): MediaFileId
    {
        $id = app(MediaFileIdResolverInterface::class)->fromStoredPath($path);
        $this->assertInstanceOf(MediaFileId::class, $id);

        return $id;
    }

    private function source(): CharacterPortraitMediaSource
    {
        return app(CharacterPortraitMediaSource::class);
    }
}
