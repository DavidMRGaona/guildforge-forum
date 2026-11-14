<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Persistence;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Modules\Forum\Application\DTOs\SpeakerChoice;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRevisionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Characters and the posts that speak as them, as stored (run on PostgreSQL too: ids are uuid
 * columns there).
 */
final class CharacterRepositoryTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private CharacterRepositoryInterface $characters;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->characters = app(CharacterRepositoryInterface::class);
        $this->owner = UserModel::factory()->create()->id;
    }

    public function test_a_character_is_stored_and_read_back_whole(): void
    {
        $category = $this->aCategory('campanas');
        $character = $this->aCharacterOf($this->owner, 'Boromir', boundTo: $category, lockedByModeration: true);
        $character->changePortrait('forum/characters/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp', new DateTimeImmutable('2026-11-12 11:00:00'));
        $this->characters->save($character);
        $this->aCharacterOf($this->owner, 'Arwen');
        $this->aCharacterOf(UserModel::factory()->create()->id, 'Ajeno');

        $found = $this->characters->findOrFail($character->id());
        $this->assertSame('Boromir', $found->name());
        $this->assertSame('Elfa de Rivendel', $found->description());
        $this->assertSame($category->id()->value, $found->categoryId()?->value);
        $this->assertTrue($found->isRetired());
        $this->assertTrue($found->isRetirementLocked());
        $this->assertSame('2026-11-12 10:00:00', $found->retiredAt()?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-12 11:00:00', $found->updatedAt()->format('Y-m-d H:i:s'));
        $this->assertSame('forum/characters/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp', $found->portraitPath());

        $this->assertSame(['Arwen', 'Boromir'], array_map(static fn (Character $owned): string => $owned->name(), $this->characters->ownedBy(strtoupper($this->owner))));
        $this->assertSame(2, $this->characters->countOwnedBy($this->owner));
        $this->assertSame([$character->id()->value], array_keys($this->characters->findMany([$character->id(), CharacterId::generate()])));
        $this->assertSame([], $this->characters->ownedBy('no-es-un-id'));
    }

    public function test_posts_and_their_versions_say_who_spoke_and_which_character_was_last_used(): void
    {
        $arwen = $this->aCharacterOf($this->owner, 'Arwen');
        $boromir = $this->aCharacterOf($this->owner, 'Boromir');
        $unused = $this->aCharacterOf($this->owner, 'Sin mensajes');
        [$discussion] = $this->aDiscussion($this->aCategory(), authorId: $this->owner);
        $writer = app(ForumWriter::class);

        $this->assertNull(app(CharacterRepositoryInterface::class)->lastUsedIn($discussion->id(), $this->owner));

        $first = $writer->reply($discussion->id(), PostId::generate(), $this->owner, self::aBody('Como Boromir'), new DateTimeImmutable('2026-11-12 11:00:00'), false, $boromir->id());
        $writer->reply($discussion->id(), PostId::generate(), $this->owner, self::aBody('Como Arwen'), new DateTimeImmutable('2026-11-12 12:00:00'), false, $arwen->id());
        $writer->reply($discussion->id(), PostId::generate(), $this->owner, self::aBody('Fuera'), new DateTimeImmutable('2026-11-12 13:00:00'), false);

        $this->assertSame($arwen->id()->value, $this->characters->lastUsedIn($discussion->id(), $this->owner)?->value);
        $this->assertSame($boromir->id()->value, app(PostRepositoryInterface::class)->findOrFail($first->id())->characterId()?->value);

        // Boromir leaves the post but stays in its history
        $writer->editPost($first->id(), self::aBody('Como Boromir'), $this->owner, new DateTimeImmutable('2026-11-12 14:00:00'), SpeakerChoice::as($arwen->id()));
        $this->assertSame($boromir->id()->value, app(PostRevisionRepositoryInterface::class)->ofPost($first->id())[0]->characterId?->value);
        $this->assertTrue($this->characters->hasPosts($boromir->id()));
        $this->assertFalse($this->characters->hasPosts($unused->id()));
        $this->assertEqualsCanonicalizing([$arwen->id()->value, $boromir->id()->value], $this->characters->idsWithPosts([$arwen->id(), $boromir->id(), $unused->id()]));

        // Deleting it leaves its posts and versions out of character
        $this->characters->delete($boromir->id());
        $this->assertNull(app(PostRevisionRepositoryInterface::class)->ofPost($first->id())[0]->characterId);
        $this->assertNull($this->characters->find($boromir->id()));
    }

    public function test_deleting_the_category_a_character_is_tied_to_keeps_it_tied(): void
    {
        $category = $this->aCategory('campanas');
        $character = $this->aCharacterOf($this->owner, boundTo: $category);

        app(CategoryRepositoryInterface::class)->delete($category->id());

        // Untying it would make it usable anywhere: it stays tied and paused (forum#109)
        $this->assertSame($category->id()->value, $this->characters->findOrFail($character->id())->categoryId()?->value);
    }

    public function test_a_shared_character_has_no_owner_and_is_listed_with_its_space(): void
    {
        $space = $this->aCategory('espacio');
        $other = $this->aCategory('otro');
        $merchant = $this->aSharedCharacterIn($space, 'Mercader');
        $this->aSharedCharacterIn($space, 'Alguacil', retired: true);
        $this->aSharedCharacterIn($other, 'De otro espacio');
        $this->aCharacterOf($this->owner, 'Arwen', boundTo: $space);

        $found = $this->characters->findOrFail($merchant->id());
        $this->assertTrue($found->isNpc());
        $this->assertNull($found->ownerId());
        $this->assertSame($space->id()->value, $found->categoryId()?->value);

        $this->assertSame(['Alguacil', 'Mercader'], array_map(static fn (Character $shared): string => $shared->name(), $this->characters->sharedIn($space->id())));
        $this->assertSame(2, $this->characters->countSharedIn($space->id()));
        $this->assertSame(['Arwen'], array_map(static fn (Character $owned): string => $owned->name(), $this->characters->ownedBy($this->owner)));
    }
}
