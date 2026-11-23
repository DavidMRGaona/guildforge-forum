<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Media;

use App\Application\Media\Contracts\ShowsMediaPreviews;
use App\Application\Media\DTOs\MediaUsageDTO;
use App\Application\Media\Services\MediaFileIdResolverInterface;
use App\Application\Media\Services\MediaSourceRegistryInterface;
use App\Domain\Media\ValueObjects\MediaFileId;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Media\CharacterPortraitMediaSource;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * A portrait counts as used while a character, retired or not, shows it; the media library
 * finds the ones replaced or left by a deleted character unused.
 */
final class CharacterPortraitMediaSourceTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
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

        $this->assertInstanceOf(ShowsMediaPreviews::class, $source);
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
        // Never seen in a post anyone reads: hidden, and its name with it
        $this->assertTrue($usages[0]->hidden);
        $this->assertSame('Personaje del foro', $usages[0]->title);
        $this->assertSame('Retrato', $usages[0]->role);
        $this->assertTrue($this->source()->usesFile($this->fileOf(self::PORTRAIT)));

        app(CharacterRepositoryInterface::class)->delete($character->id());
        $this->assertFalse($this->source()->usesFile($this->fileOf(self::PORTRAIT)));
    }

    public function test_a_portrait_shows_once_its_character_speaks_in_a_post_every_member_reads(): void
    {
        $character = $this->aPortraitedCharacter('Arwen');
        $this->speakIn($this->aCategoryFor(CategoryAudience::everyone(), 'general'), $character);

        $usage = $this->onlyUsageOf($character);

        $this->assertFalse($usage->hidden);
        $this->assertSame('Personaje Arwen', $usage->title);
    }

    public function test_a_portrait_seen_only_in_narrower_categories_stays_hidden_under_a_neutral_title(): void
    {
        $character = $this->aPortraitedCharacter('Espía de la junta');
        $this->speakIn($this->aCategoryFor(CategoryAudience::associationMembers(), 'socios'), $character);

        $usage = $this->onlyUsageOf($character);

        $this->assertTrue($usage->hidden);
        $this->assertSame('Personaje del foro', $usage->title);
    }

    public function test_the_shared_character_of_a_campaign_space_members_do_not_read_stays_hidden(): void
    {
        $space = $this->aCategoryFor(CategoryAudience::roles([(string) Str::uuid()]), 'espacio');
        $npc = $this->aSharedCharacterIn($space, 'Villano secreto');
        $npc->changePortrait(self::PORTRAIT, new DateTimeImmutable);
        app(CharacterRepositoryInterface::class)->save($npc);
        $this->speakIn($space, $npc);

        $usage = $this->onlyUsageOf($npc);

        $this->assertTrue($usage->hidden);
        $this->assertSame('Personaje del foro', $usage->title);
    }

    public function test_the_walk_and_one_record_agree(): void
    {
        $shown = $this->aPortraitedCharacter('Arwen');
        $this->speakIn($this->aCategoryFor(CategoryAudience::everyone(), 'general'), $shown);
        $this->aPortraitedCharacter('Nadie', 'forum/characters/2026/11/1c6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5b.png');

        $hidden = [];
        foreach ($this->source()->usages() as $usage) {
            $hidden[$usage->title] = $usage->hidden;
        }

        $this->assertEquals(['Personaje Arwen' => false, 'Personaje del foro' => true], $hidden);
    }

    private function aPortraitedCharacter(string $name, string $portrait = self::PORTRAIT): Character
    {
        $character = $this->aCharacterOf(UserModel::factory()->create()->id, $name);
        $character->changePortrait($portrait, new DateTimeImmutable);
        app(CharacterRepositoryInterface::class)->save($character);

        return $character;
    }

    private function speakIn(Category $category, Character $character): void
    {
        [$discussion] = $this->aDiscussion($category);
        app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), $character->ownerId(), self::aBody('En personaje'), new DateTimeImmutable('2026-11-12 11:00:00'), false, $character->id());
    }

    private function onlyUsageOf(Character $character): MediaUsageDTO
    {
        $usages = $this->source()->usagesFor($character->id()->value);
        $this->assertCount(1, $usages);

        return $usages[0];
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
