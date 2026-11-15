<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\DTOs\PostWriterDTO;
use Modules\Forum\Application\DTOs\WriterNameDTO;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CharacterId;

/**
 * The one rule that names the writer of a post that speaks as a personal character, or spoke as
 * one deleted since (forum#139): by their public name only, never by the account name the
 * directory falls back to. Without a public name, the character the reader may see is named, or
 * else «Personaje eliminado» (or «Personaje no disponible» when the reader may not see it).
 *
 * A post out of character, or as a shared character, is not this rule's business: the first
 * names its account as always, the second its character (see `PostContentPresenter::hidesWriter()`).
 * The writer and whoever moderates the discussion are left out by the callers that know them.
 */
final readonly class CharacterWriterNames
{
    public function __construct(
        private AccountDirectoryInterface $accounts,
        private CharacterRepositoryInterface $characters,
        private CharacterRules $rules,
    ) {}

    /**
     * Whether the account has a public name (or is anonymized, whose generic name tells nothing).
     */
    public function hasPublicName(string $authorId): bool
    {
        return $this->accounts->publicNamesOf([$authorId]) !== [];
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, PostWriterDTO>  $writers
     * @return array<TKey, WriterNameDTO> only for the posts the rule names, keyed like `$writers`
     */
    public function namesFor(array $writers, ?Authenticatable $reader): array
    {
        $candidates = array_filter($writers, static fn (PostWriterDTO $writer): bool => $writer->characterRemoved || $writer->characterId !== null);
        if ($candidates === []) {
            return [];
        }

        $ids = [];
        foreach ($candidates as $writer) {
            if ($writer->characterId !== null && Str::isUuid($writer->characterId)) {
                $ids[strtolower($writer->characterId)] = CharacterId::fromString(strtolower($writer->characterId));
            }
        }
        $characters = $ids === [] ? [] : $this->characters->findMany(array_values($ids));
        $seen = [];
        foreach ($characters as $id => $character) {
            $seen[(string) $id] = $this->rules->maySee($reader, $character);
        }

        $named = [];
        foreach ($candidates as $key => $writer) {
            $character = $writer->characterId === null ? null : ($characters[strtolower($writer->characterId)] ?? null);
            // A shared character names itself, never its writer
            if ($character instanceof Character && $character->isNpc()) {
                continue;
            }
            $named[$key] = $character;
        }
        if ($named === []) {
            return [];
        }

        $authorIds = array_values(array_unique(array_filter(array_map(
            static fn (PostWriterDTO $writer): ?string => $writer->authorId === null ? null : strtolower($writer->authorId),
            array_intersect_key($candidates, $named),
        ))));
        $publicNames = $authorIds === [] ? [] : $this->accounts->publicNamesOf($authorIds);

        $names = [];
        foreach ($named as $key => $character) {
            $writer = $candidates[$key];
            $seenCharacter = $character instanceof Character && ($seen[$character->id()->value] ?? false) ? $character : null;
            $publicName = $writer->authorId === null ? null : ($publicNames[strtolower($writer->authorId)] ?? null);

            $names[$key] = new WriterNameDTO(
                name: $publicName ?? $seenCharacter?->name() ?? __($character instanceof Character
                    ? 'forum::messages.posts.unavailable_character'
                    : 'forum::messages.posts.removed_character'),
                isPublicName: $publicName !== null,
                seenCharacter: $seenCharacter,
            );
        }

        return $names;
    }
}
