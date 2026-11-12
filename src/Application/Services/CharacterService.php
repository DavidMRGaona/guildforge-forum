<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Modules\Forum\Application\DTOs\CharacterInputDTO;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Exceptions\CharacterNotFoundException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidCharacterException;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CharacterId;

/**
 * What people do with characters: their owner creates, changes, retires, brings back and
 * deletes them (one with posts is retired instead); moderators change, retire, bring back and
 * delete any they moderate. A character the person may neither own nor moderate answers as if
 * it did not exist. Who may do what is CharacterRules'.
 */
final readonly class CharacterService
{
    /** Characters one account may have, retired ones included */
    public const int MAX_PER_ACCOUNT = 30;

    public function __construct(
        private CharacterRepositoryInterface $characters,
        private CharacterRules $rules,
        private CharacterPortraitUploader $portraits,
    ) {}

    /**
     * The person's characters, retired ones included, by name.
     *
     * @return list<Character>
     */
    public function ownedBy(Authenticatable $owner): array
    {
        return $this->characters->ownedBy((string) ForumBrowser::accountId($owner));
    }

    /**
     * @throws ForumActionDeniedException when characters are off for the person
     * @throws InvalidCharacterException
     */
    public function create(Authenticatable $owner, CharacterInputDTO $input): Character
    {
        if (! $this->rules->mayHaveCharacters($owner)) {
            throw ForumActionDeniedException::because('have characters');
        }

        $ownerId = (string) ForumBrowser::accountId($owner);
        if ($this->characters->countOwnedBy($ownerId) >= self::MAX_PER_ACCOUNT) {
            throw InvalidCharacterException::because(InvalidCharacterException::TOO_MANY, 'name', 'Too many characters for one account.');
        }

        $now = CarbonImmutable::now();
        $character = Character::create(CharacterId::generate(), $ownerId, $input->name, $input->description, $this->bindableCategory($owner, $input->categoryId, null), $now);
        if ($input->portrait !== null) {
            $character->changePortrait($this->portraits->upload($input->portrait), $now);
        }

        $this->characters->save($character);

        return $character;
    }

    /**
     * Its owner changes all of it; a moderator its name, description and portrait (the category
     * it is tied to stays).
     *
     * @throws CharacterNotFoundException
     * @throws ForumActionDeniedException when its owner may no longer have characters
     * @throws InvalidCharacterException
     */
    public function update(Authenticatable $actor, string $id, CharacterInputDTO $input): Character
    {
        $character = $this->manageable($actor, $id);
        $owner = $this->rules->owns($actor, $character);
        if ($owner && ! $this->rules->mayHaveCharacters($actor) && ! $this->rules->moderates($actor, $character)) {
            throw ForumActionDeniedException::because('change characters');
        }

        $categoryId = $owner ? $this->bindableCategory($actor, $input->categoryId, $character->categoryId()) : $character->categoryId();
        $now = CarbonImmutable::now();
        $character->change($input->name, $input->description, $categoryId, $now);

        if ($input->portrait !== null) {
            $character->changePortrait($this->portraits->upload($input->portrait), $now);
        } elseif ($input->removePortrait) {
            $character->changePortrait(null, $now);
        }

        $this->characters->save($character);

        return $character;
    }

    /**
     * Nobody writes as it any more. Retired by a moderator (not its owner), its owner cannot
     * bring it back.
     *
     * @throws CharacterNotFoundException
     * @throws InvalidCharacterException when it is retired already
     */
    public function retire(Authenticatable $actor, string $id): Character
    {
        $character = $this->manageable($actor, $id);
        $character->retire(CarbonImmutable::now(), byModeration: ! $this->rules->owns($actor, $character));
        $this->characters->save($character);

        return $character;
    }

    /**
     * @throws CharacterNotFoundException
     * @throws ForumActionDeniedException when its owner may no longer have characters
     * @throws InvalidCharacterException unless it is retired, or when moderation retired it and its owner asks
     */
    public function restore(Authenticatable $actor, string $id): Character
    {
        $character = $this->manageable($actor, $id);
        $moderates = $this->rules->moderates($actor, $character);
        if ($this->rules->owns($actor, $character) && ! $moderates && ! $this->rules->mayHaveCharacters($actor)) {
            throw ForumActionDeniedException::because('bring characters back');
        }

        $character->restore(CarbonImmutable::now(), byModeration: $moderates);
        $this->characters->save($character);

        return $character;
    }

    /**
     * Its owner deletes it only while no post speaks as it (otherwise it is retired); a
     * moderator deletes any, and its posts show their account from then on.
     *
     * @throws CharacterNotFoundException
     * @throws InvalidCharacterException when its owner deletes one with posts
     */
    public function delete(Authenticatable $actor, string $id): void
    {
        $character = $this->manageable($actor, $id);
        if (! $this->rules->moderates($actor, $character) && $this->characters->hasPosts($character->id())) {
            throw InvalidCharacterException::because(InvalidCharacterException::HAS_POSTS, 'character', "Character {$id} has posts: it is retired instead.");
        }

        $this->characters->delete($character->id());
    }

    /**
     * Whether a post or more speak as it (deleting it then retires it, for its owner).
     *
     * @throws CharacterNotFoundException
     */
    public function hasPosts(Authenticatable $actor, string $id): bool
    {
        return $this->characters->hasPosts($this->manageable($actor, $id)->id());
    }

    /**
     * Which of the characters any post speaks as (their owner retires them instead of deleting).
     *
     * @param  list<Character>  $characters
     * @return list<string> their ids
     */
    public function idsWithPosts(array $characters): array
    {
        return $this->characters->idsWithPosts(array_map(static fn (Character $character): CharacterId => $character->id(), $characters));
    }

    /**
     * A character the person owns or moderates.
     *
     * @throws CharacterNotFoundException
     */
    public function manageable(Authenticatable $actor, string $id): Character
    {
        $character = Str::isUuid($id) ? $this->characters->find(CharacterId::fromString(strtolower($id))) : null;
        if (! $character instanceof Character || (! $this->rules->owns($actor, $character) && ! $this->rules->moderates($actor, $character))) {
            throw CharacterNotFoundException::withId($id);
        }

        return $character;
    }

    /**
     * The category to tie it to: none, the one it already has, or one the owner may write in.
     *
     * @throws InvalidCharacterException
     */
    private function bindableCategory(Authenticatable $owner, ?string $wanted, ?CategoryId $current): ?CategoryId
    {
        if ($wanted === null || $wanted === '') {
            return null;
        }

        $wanted = strtolower($wanted);
        if ($current instanceof CategoryId && $current->value === $wanted) {
            return $current;
        }

        foreach ($this->rules->bindableCategories($owner) as $category) {
            if ($category->id()->value === $wanted) {
                return $category->id();
            }
        }

        throw InvalidCharacterException::because(InvalidCharacterException::CATEGORY, 'category', "Category {$wanted} cannot hold characters of this account.");
    }

    /**
     * The categories a character of the person may be tied to.
     *
     * @return list<Category>
     */
    public function bindableCategories(Authenticatable $owner): array
    {
        return $this->rules->bindableCategories($owner);
    }
}
