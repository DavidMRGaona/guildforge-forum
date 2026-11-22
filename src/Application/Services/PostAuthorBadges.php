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
use Modules\Forum\Domain\ValueObjects\CharacterId;

/**
 * The featured badges next to the authors of a page of posts (forum#152), from the core
 * (ProfileBadgesInterface): asked once for the whole page, with every author it names, and the
 * core decides on the profile's own rule which of them the reader may see.
 *
 * Only where the post names a person to the reader, and never where that could tell who is behind
 * a character: not for a shared character (whoever writes it, even to moderation or its writer),
 * not for a character the reader may not see, and not in character for a writer without a public
 * name (CharacterWriterNames hides who they are; their badges would point at them). The forum's
 * own account has none.
 */
final readonly class PostAuthorBadges
{
    public function __construct(
        private ProfileBadgesInterface $badges,
        private AccountDirectoryInterface $accounts,
        private SystemAccountInterface $systemAccount,
    ) {}

    /**
     * @param  list<Post>  $posts
     * @param  list<PostContentDTO>  $contents  what each post shows to the reader, in the same order
     * @return array<int, list<ProfileBadgeDTO>> by the posts' index; only posts whose author shows badges
     */
    public function forPosts(array $posts, array $contents, ?Authenticatable $reader): array
    {
        $authors = [];
        $inCharacter = [];
        foreach ($posts as $index => $post) {
            $content = $contents[$index] ?? null;
            $authorId = $post->authorId() === null ? null : strtolower($post->authorId());
            if ($authorId === null || ! $content instanceof PostContentDTO || $content->authorHidden || $content->unnamedWriter instanceof WriterNameDTO) {
                continue;
            }

            if ($post->characterId() instanceof CharacterId || $post->spokeAsRemovedCharacter()) {
                // Who the post speaks as must be a personal character the reader sees, or one deleted since
                $character = $content->character;
                $personal = $character instanceof Character ? ! $character->isNpc() : $post->spokeAsRemovedCharacter();
                if (! $personal) {
                    continue;
                }
                $inCharacter[$authorId] = true;
            }

            $authors[$index] = $authorId;
        }

        if ($inCharacter !== []) {
            $named = array_change_key_case($this->accounts->publicNamesOf(array_keys($inCharacter)));
            foreach ($authors as $index => $authorId) {
                $post = $posts[$index];
                $speaksAsCharacter = $post->characterId() instanceof CharacterId || $post->spokeAsRemovedCharacter();
                if ($speaksAsCharacter && ! isset($named[$authorId])) {
                    unset($authors[$index]);
                }
            }
        }

        $ids = array_values(array_filter(
            array_unique($authors),
            fn (string $id): bool => ! $this->systemAccount->isSystemAccount($id),
        ));
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
}
