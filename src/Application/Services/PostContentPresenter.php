<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\DTOs\PostContentDTO;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CharacterId;

/**
 * What each post of a discussion shows to a reader. A post its author deleted keeps its place
 * as «Mensaje borrado» and a hidden one as «Mensaje oculto»; their moderators still read them,
 * and a hidden post's author does too, with the reason it was hidden. Moderators may edit any
 * post that was not deleted. The pages decide which posts to list (pending ones only
 * to their author and moderators).
 */
final readonly class PostContentPresenter
{
    public function __construct(
        private ForumAccessInterface $access,
        private PostingService $posting,
        private PostRenderer $renderer,
        private CharacterRepositoryInterface $characters,
        private CharacterRules $characterRules,
    ) {}

    /**
     * @param  list<Post>  $posts  of the discussion
     * @return list<PostContentDTO> in the same order
     */
    public function present(array $posts, Discussion $discussion, ?Authenticatable $reader): array
    {
        $readerId = $reader instanceof Authenticatable ? strtolower(is_scalar($reader->getAuthIdentifier()) ? (string) $reader->getAuthIdentifier() : '') : null;
        $moderates = $this->access->canModerate($reader, $discussion);
        $canPost = $this->posting->canPost($reader, $discussion);

        $shown = array_values(array_filter($posts, fn (Post $post): bool => $this->showsContent($post, $readerId, $moderates)));
        $characters = $this->charactersOf($posts, $reader);
        $html = array_combine(
            array_map(static fn (Post $post): string => $post->id()->value, $shown),
            $this->renderer->toHtml(
                array_map(static fn (Post $post) => $post->body(), $shown),
                $reader,
                array_map(static fn (Post $post): ?string => ($characters[$post->characterId()->value ?? ''] ?? null)?->name(), $shown),
            ),
        );
        /** @var array<string, bool> $moderated */
        $moderated = [];
        foreach ($characters as $id => $character) {
            $moderated[$id] = $this->characterRules->moderates($reader, $character);
        }

        return array_map(function (Post $post) use ($html, $readerId, $canPost, $moderates, $characters, $moderated): PostContentDTO {
            $own = $readerId !== null && $post->authorId() === $readerId;
            $ownEditable = $own && $canPost && $post->status() !== PostStatus::Deleted;
            // Moderators edit anyone's post; the version it replaces stays in its history
            $editable = $ownEditable || ($moderates && $post->status() !== PostStatus::Deleted);

            return new PostContentDTO(
                id: $post->id()->value,
                number: $post->number()?->value,
                authorId: $post->authorId(),
                status: $post->status(),
                html: $html[$post->id()->value] ?? null,
                placeholder: $this->placeholder($post->status()),
                editedAt: $post->editedAt(),
                canEdit: $editable,
                canDelete: $ownEditable && $post->status() !== PostStatus::Pending,
                hiddenReason: $own || $moderates ? $post->hiddenReason() : null,
                character: $characters[$post->characterId()->value ?? ''] ?? null,
                moderatesCharacter: $moderated[$post->characterId()->value ?? ''] ?? false,
                authorHidden: ! $own && ! $moderates && (($characters[$post->characterId()->value ?? ''] ?? null)?->isNpc() ?? false)
                    && ! ($moderated[$post->characterId()->value ?? ''] ?? false),
            );
        }, $posts);
    }

    /**
     * The characters the posts speak as that the reader may see, by id. A post whose character
     * the reader may not see shows its account, as one out of character.
     *
     * @param  list<Post>  $posts
     * @return array<string, Character>
     */
    private function charactersOf(array $posts, ?Authenticatable $reader): array
    {
        $ids = array_values(array_filter(array_map(static fn (Post $post): ?CharacterId => $post->characterId(), $posts)));

        return array_filter(
            $this->characters->findMany($ids),
            fn (Character $character): bool => $this->characterRules->maySee($reader, $character),
        );
    }

    private function showsContent(Post $post, ?string $readerId, bool $moderates): bool
    {
        return match ($post->status()) {
            PostStatus::Visible => true,
            PostStatus::Hidden, PostStatus::Pending => $moderates || ($readerId !== null && $post->authorId() === $readerId),
            // Once purged there is nothing left to read
            PostStatus::Deleted => $moderates && ! $post->isContentPurged(),
        };
    }

    private function placeholder(PostStatus $status): ?string
    {
        $key = match ($status) {
            PostStatus::Visible => null,
            PostStatus::Hidden => 'forum::messages.posts.hidden',
            PostStatus::Pending => 'forum::messages.posts.pending',
            PostStatus::Deleted => 'forum::messages.posts.deleted',
        };

        $text = $key === null ? null : __($key);

        return is_string($text) ? $text : null;
    }
}
