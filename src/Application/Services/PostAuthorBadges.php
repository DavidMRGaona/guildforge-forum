<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Profiles\DTOs\ProfileBadgeDTO;
use App\Application\Profiles\Services\ProfileBadgesInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\DTOs\PostContentDTO;
use Modules\Forum\Application\DTOs\WriterNameDTO;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CharacterId;

/**
 * The featured badges next to the authors of a page of posts (forum#152), from the core
 * (ProfileBadgesInterface): asked once for the whole page, with every author it names, and the
 * core decides on the profile's own rule which of them the reader may see.
 *
 * Badges follow the writer's name: only where the post names a person to the reader (no
 * `authorHidden`, no `unnamedWriter`), as a post out of character does, and never where they
 * could tell who is behind a character. So never for a shared character, whoever looks (its
 * writer and moderation included), and in character only for a writer with a public name (CharacterWriterNames hides who the others are; their
 * badges would point at them). The forum's own account has none. Authors left out are never
 * sent to the core.
 */
final readonly class PostAuthorBadges
{
    public function __construct(
        private ProfileBadgesInterface $badges,
        private AccountDirectoryInterface $accounts,
        private SystemAccountInterface $systemAccount,
        private CharacterRepositoryInterface $characters,
    ) {}

    /**
     * @param  list<Post>  $posts
     * @param  list<PostContentDTO>  $contents  what each post shows to the reader, in the same order
     * @return array<int, list<ProfileBadgeDTO>> by the posts' index; only posts whose author shows badges
     */
    public function forPosts(array $posts, array $contents, ?Authenticatable $reader): array
    {
        $authors = [];
        $seen = [];
        foreach ($posts as $index => $post) {
            $content = $contents[$index] ?? null;
            $authorId = $post->authorId() === null ? null : strtolower($post->authorId());
            if ($authorId === null || ! $content instanceof PostContentDTO || $content->authorHidden || $content->unnamedWriter instanceof WriterNameDTO) {
                continue;
            }

            $authors[$index] = $authorId;
            $seen[$index] = $content->character;
        }

        $authors = $this->showingBadges($posts, $authors, $seen);
        $ids = array_values(array_unique($authors));
        if ($ids === []) {
            return [];
        }

        $badges = $this->badges->forAuthors($ids, $reader);

        $byPost = [];
        foreach ($authors as $index => $authorId) {
            if (($badges[$authorId] ?? []) !== []) {
                $byPost[$index] = $badges[$authorId];
            }
        }

        return $byPost;
    }

    /**
     * Whether the card of this post's author may show the profile's badges: the same rule as
     * next to the name (the card only opens where the post names its writer).
     */
    public function cardShowsBadges(Post $post): bool
    {
        $authorId = $post->authorId();

        if ($authorId === null) {
            return false;
        }
        // Whoever opens it may know the character, or not: it is read for the rule either way
        $characterId = $post->characterId();
        $character = $characterId instanceof CharacterId ? $this->characters->find($characterId) : null;

        return $this->showingBadges([$post], [strtolower($authorId)], [$character]) !== [];
    }

    /**
     * The authors, of those the post names, whose badges may show: not the forum's account, not
     * as a shared character, and in character only with a public name.
     *
     * @param  array<int, Post>  $posts
     * @param  array<int, string>  $authors  lower-case account id by the posts' index
     * @param  array<int, Character|null>  $seen  the character each post speaks as, when the reader may see it
     * @return array<int, string> those of `$authors` that may show badges
     */
    private function showingBadges(array $posts, array $authors, array $seen): array
    {
        $authors = array_filter($authors, fn (string $id): bool => ! $this->systemAccount->isSystemAccount($id));

        $inCharacter = [];
        foreach ($authors as $index => $authorId) {
            $post = $posts[$index];
            $characterId = $post->characterId();
            if (! $characterId instanceof CharacterId && ! $post->spokeAsRemovedCharacter()) {
                continue;
            }

            // A character the reader may not see is a personal one: shared ones are always seen
            // (CharacterRules::maySee()), and a deleted one was personal (a shared one with posts is
            // retired, never deleted)
            $character = $seen[$index] ?? null;
            if ($character instanceof Character && $character->isNpc()) {
                unset($authors[$index]);

                continue;
            }
            $inCharacter[$index] = $authorId;
        }

        if ($inCharacter !== []) {
            $named = array_change_key_case($this->accounts->publicNamesOf(array_values(array_unique($inCharacter))));
            foreach ($inCharacter as $index => $authorId) {
                if (! isset($named[$authorId])) {
                    unset($authors[$index]);
                }
            }
        }

        return $authors;
    }
}
