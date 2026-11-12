<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Modules\Forum\Application\DTOs\SpeakerChoice;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\CharacterUnavailableException;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * Who a post speaks as. The editor sends a character id (or none, out of character); the server
 * checks it every time a post is written or edited: nobody writes as someone else's character,
 * a retired one, or one tied to another category (CharacterRules::usableIn).
 *
 * Editing a post keeps the character it already speaks as, even if that one was retired since:
 * fixing a typo never forces a change. Switching to any other one is checked as for a new post.
 */
final readonly class PostSpeakers
{
    public function __construct(
        private CharacterRepositoryInterface $characters,
        private CharacterRules $rules,
    ) {}

    /**
     * The character a new post in the category speaks as.
     *
     * @throws CharacterUnavailableException
     */
    public function forNewPost(Authenticatable $author, ?string $characterId, Category $category): ?CharacterId
    {
        if ($characterId === null || $characterId === '') {
            return null;
        }

        return $this->usable($author, $characterId, $category)->id();
    }

    /**
     * Who an edited post speaks as.
     *
     * @param  bool  $given  whether the editor said (an older client, or a moderator's edit, does not)
     *
     * @throws CharacterUnavailableException
     */
    public function forEdit(Authenticatable $author, Post $post, Category $category, bool $given, ?string $characterId): SpeakerChoice
    {
        if (! $given) {
            return SpeakerChoice::keep();
        }

        if ($characterId === null || $characterId === '') {
            return SpeakerChoice::as(null);
        }

        $current = $post->characterId();
        if ($current instanceof CharacterId && strtolower($characterId) === $current->value) {
            return SpeakerChoice::keep();
        }

        return SpeakerChoice::as($this->usable($author, $characterId, $category)->id());
    }

    /**
     * The characters the person may write as in the category, by name.
     *
     * @return list<Character>
     */
    public function usableIn(Authenticatable $user, Category $category): array
    {
        if (! $this->rules->mayHaveCharacters($user)) {
            return [];
        }

        return array_values(array_filter(
            $this->characters->ownedBy((string) ForumBrowser::accountId($user)),
            fn (Character $character): bool => $this->rules->usableIn($user, $character, $category),
        ));
    }

    /**
     * The character a reply is offered with: the last one the person spoke as in the discussion,
     * when they may still use it there.
     *
     * @param  list<Character>  $usable  what usableIn() gave for the discussion's category
     */
    public function suggestedFor(Authenticatable $user, DiscussionId $discussionId, array $usable): ?CharacterId
    {
        if ($usable === []) {
            return null;
        }

        $last = $this->characters->lastUsedIn($discussionId, (string) ForumBrowser::accountId($user));
        foreach ($usable as $character) {
            if ($last instanceof CharacterId && $character->id()->equals($last)) {
                return $last;
            }
        }

        return null;
    }

    /**
     * @throws CharacterUnavailableException
     */
    private function usable(Authenticatable $author, string $characterId, Category $category): Character
    {
        $character = Str::isUuid($characterId) ? $this->characters->find(CharacterId::fromString(strtolower($characterId))) : null;
        if (! $character instanceof Character || ! $this->rules->usableIn($author, $character, $category)) {
            throw CharacterUnavailableException::for($characterId);
        }

        return $character;
    }
}
