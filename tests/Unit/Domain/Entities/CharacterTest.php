<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Exceptions\InvalidCharacterException;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CharacterTest extends TestCase
{
    private const string OWNER = '9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6';

    public function test_a_name_is_trimmed_and_its_spaces_collapsed(): void
    {
        $character = $this->aCharacter("  Arwen \n  Undómiel ");

        $this->assertSame('Arwen Undómiel', $character->name());
        $this->assertSame('AU', $character->initials());
        $this->assertTrue($character->isOwnedBy(strtoupper(self::OWNER)));
        $this->assertFalse($character->isOwnedBy('11111111-1b3c-4d5e-8f70-a1b2c3d4e5f6'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'empty' => ['   '];
        yield 'too long' => [str_repeat('a', Character::MAX_NAME_LENGTH + 1)];
    }

    #[DataProvider('badNames')]
    public function test_a_name_needs_one_to_sixty_characters(string $name): void
    {
        try {
            $this->aCharacter($name);
            $this->fail('A name out of bounds was taken.');
        } catch (InvalidCharacterException $exception) {
            $this->assertSame(InvalidCharacterException::NAME, $exception->reason);
        }
    }

    public function test_a_blank_description_is_none_and_a_long_one_is_refused(): void
    {
        $this->assertNull($this->aCharacter('Arwen', "  \n ")->description());
        $this->assertSame("Elfa.\nDe Rivendel.", $this->aCharacter('Arwen', "Elfa.\r\nDe   Rivendel.")->description());

        $this->expectException(InvalidCharacterException::class);
        $this->aCharacter('Arwen', str_repeat('a', Character::MAX_DESCRIPTION_LENGTH + 1));
    }

    public function test_a_portrait_must_live_in_the_forum_characters_folder(): void
    {
        $character = $this->aCharacter('Arwen');
        $character->changePortrait('forum/characters/2026/11/9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6.png', new DateTimeImmutable);
        $this->assertSame('forum/characters/2026/11/9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6.png', $character->portraitPath());

        $this->expectException(InvalidCharacterException::class);
        $character->changePortrait('forum/posts/2026/11/9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6.png', new DateTimeImmutable);
    }

    public function test_its_owner_brings_back_what_they_retired_but_not_what_moderation_did(): void
    {
        $character = $this->aCharacter('Arwen');
        $character->retire(new DateTimeImmutable('2026-11-12 10:00:00'), byModeration: false);
        $this->assertTrue($character->isRetired());
        $this->assertFalse($character->isRetirementLocked());

        $character->restore(new DateTimeImmutable, byModeration: false);
        $this->assertFalse($character->isRetired());

        $character->retire(new DateTimeImmutable, byModeration: true);
        $this->assertTrue($character->isRetirementLocked());

        try {
            $character->restore(new DateTimeImmutable, byModeration: false);
            $this->fail('The owner brought back a character moderation retired.');
        } catch (InvalidCharacterException $exception) {
            $this->assertSame(InvalidCharacterException::LOCKED, $exception->reason);
        }

        $character->restore(new DateTimeImmutable, byModeration: true);
        $this->assertFalse($character->isRetired());
        $this->assertFalse($character->isRetirementLocked());
    }

    public function test_moderation_locks_a_character_its_owner_retired_and_keeps_when_it_was_retired(): void
    {
        $character = $this->aCharacter('Arwen');
        $character->retire(new DateTimeImmutable('2026-11-12 10:00:00'), byModeration: false);
        $character->retire(new DateTimeImmutable('2026-11-13 10:00:00'), byModeration: true);

        $this->assertTrue($character->isRetirementLocked());
        $this->assertSame('2026-11-12 10:00:00', $character->retiredAt()?->format('Y-m-d H:i:s'));

        $this->expectException(InvalidCharacterException::class);
        $character->retire(new DateTimeImmutable, byModeration: true);
    }

    public function test_an_anonymized_account_keeps_the_name_and_loses_the_rest(): void
    {
        $character = $this->aCharacter('Arwen', 'Elfa de Rivendel');
        $character->changePortrait('forum/characters/2026/11/9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6.webp', new DateTimeImmutable);

        $character->stripPersonalDetails(new DateTimeImmutable);

        $this->assertSame('Arwen', $character->name());
        $this->assertNull($character->description());
        $this->assertNull($character->portraitPath());
    }

    private function aCharacter(string $name, ?string $description = null): Character
    {
        return Character::create(CharacterId::generate(), self::OWNER, $name, $description, null, new DateTimeImmutable('2026-11-12 09:00:00'));
    }
}
