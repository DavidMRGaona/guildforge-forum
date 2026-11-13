<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Forum\Application\DTOs\CharacterInputDTO;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Exceptions\CharacterNotFoundException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidCharacterException;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CharacterId;

/**
 * What people do with characters: their owner creates, changes, retires, brings back and
 * deletes them (one with posts is retired instead); moderators change, retire, bring back and
 * delete any they moderate; what moderation does goes to the moderation log, with the
 * character's name. A character the person may neither own nor moderate answers as if it did
 * not exist. Who may do what is CharacterRules'.
 */
final readonly class CharacterService
{
    /** Characters one account may have, retired ones included */
    public const int MAX_PER_ACCOUNT = 30;

    public function __construct(
        private CharacterRepositoryInterface $characters,
        private CharacterRules $rules,
        private CharacterPortraitUploader $portraits,
        private ModerationLogger $logger,
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
     * The shared characters of a campaign space, retired ones included, by name: for whoever
     * directs the campaign and whoever moderates the space.
     *
     * @return list<Character>
     *
     * @throws ForumActionDeniedException unless the person directs or moderates the space
     */
    public function sharedIn(Authenticatable $actor, Category $space): array
    {
        if (! $this->rules->mayManageSharedIn($actor, $space)) {
            throw ForumActionDeniedException::because('manage the shared characters of this space');
        }

        return $this->characters->sharedIn($space->id());
    }

    /**
     * A shared character of the campaign space, for whoever directs the campaign.
     *
     * @throws ForumActionDeniedException unless the person directs the campaign and characters are on for them
     * @throws InvalidCharacterException
     */
    public function createShared(Authenticatable $director, Category $space, CharacterInputDTO $input): Character
    {
        if (! $this->rules->mayShareIn($director, $space)) {
            throw ForumActionDeniedException::because('share characters in this space');
        }

        if ($this->characters->countSharedIn($space->id()) >= CharacterRules::MAX_SHARED_PER_SPACE) {
            throw InvalidCharacterException::because(InvalidCharacterException::TOO_MANY, 'name', 'Too many shared characters for one space.');
        }

        $now = CarbonImmutable::now();
        $character = Character::createNpc(CharacterId::generate(), $input->name, $input->description, $space->id(), $now);
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

        $this->saveLogged($actor, $character, ModerationAction::CharacterEdited);

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
        // Retired by someone who does not direct its campaign, a shared one stays retired for the directors
        $character->retire(CarbonImmutable::now(), byModeration: $character->isNpc() ? ! $this->rules->leads($actor, $character) : ! $this->rules->owns($actor, $character));
        $this->saveLogged($actor, $character, ModerationAction::CharacterRetired);

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
        $moderates = $character->isNpc() ? ! $this->rules->leads($actor, $character) : $this->rules->moderates($actor, $character);
        if ($this->rules->owns($actor, $character) && ! $moderates && ! $this->rules->mayHaveCharacters($actor)) {
            throw ForumActionDeniedException::because('bring characters back');
        }

        $character->restore(CarbonImmutable::now(), byModeration: $moderates);
        $this->saveLogged($actor, $character, ModerationAction::CharacterRestored);

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
        $hasPosts = $this->characters->hasPosts($character->id());
        // A shared one with posts is never deleted: its posts would show who wrote them
        if (($character->isNpc() || ! $this->rules->moderates($actor, $character)) && $hasPosts) {
            throw InvalidCharacterException::because(InvalidCharacterException::HAS_POSTS, 'character', "Character {$id} has posts: it is retired instead.");
        }

        DB::transaction(function () use ($actor, $character, $hasPosts): void {
            $this->characters->delete($character->id());
            $this->logIfModeration($actor, $character, ModerationAction::CharacterDeleted, ['had_posts' => $hasPosts]);
        });
    }

    /**
     * Saves the character and, when moderation changed it (not its owner), logs what was done
     * in the same transaction.
     */
    private function saveLogged(Authenticatable $actor, Character $character, ModerationAction $action): void
    {
        DB::transaction(function () use ($actor, $character, $action): void {
            $this->characters->save($character);
            $this->logIfModeration($actor, $character, $action);
        });
    }

    /**
     * @param  array<string, string|int|bool|null>  $details
     */
    private function logIfModeration(Authenticatable $actor, Character $character, ModerationAction $action, array $details = []): void
    {
        // What the directors do with their shared characters is theirs, not moderation's
        $ownerId = $character->ownerId();
        if ($ownerId === null || $this->rules->owns($actor, $character)) {
            return;
        }

        $this->logger->recordForAccount($action, $actor, $ownerId, details: [
            'character_id' => $character->id()->value,
            'character_name' => $character->name(),
            ...$details,
        ]);
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
