<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\DTOs\PostContentDTO;
use Modules\Forum\Application\DTOs\PostWriterDTO;
use Modules\Forum\Application\DTOs\WriterNameDTO;
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
        private ReplyCounts $replyCounts,
        private CharacterWriterNames $writerNames,
    ) {}

    /**
     * @param  list<Post>  $posts  of the discussion
     * @param  bool  $withoutMedia  whether images and embeds are left out (a long post in a preview)
     * @param  bool  $offerReveal  whether hidden rolls offer «Revelar» (not where the post is not read again after it)
     * @return list<PostContentDTO> in the same order
     */
    public function present(array $posts, Discussion $discussion, ?Authenticatable $reader, bool $withoutMedia = false, bool $offerReveal = true): array
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
                offerReveal: $offerReveal,
                withoutMedia: $withoutMedia,
            ),
        );
        $replies = $this->replyCounts->countsFor($posts, $reader);
        $unnamed = $this->unnamedWriters($posts, $reader, $readerId, $moderates);
        /** @var array<string, bool> $moderated */
        $moderated = [];
        foreach ($characters as $id => $character) {
            $moderated[$id] = $this->characterRules->moderates($reader, $character);
        }

        return array_map(function (Post $post) use ($html, $readerId, $canPost, $moderates, $characters, $moderated, $replies, $unnamed): PostContentDTO {
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
                authorHidden: $this->hidesWriter($own, $moderates, $characters[$post->characterId()->value ?? ''] ?? null, $moderated[$post->characterId()->value ?? ''] ?? false),
                replyCount: $replies[$post->id()->value] ?? 0,
                unnamedWriter: $unnamed[$post->id()->value] ?? null,
            );
        }, $posts);
    }

    /**
     * The posts that name their writer as a character or with a neutral label, by post id: those
     * that speak, or spoke, as a personal character of someone without a public name. Never for
     * their writer or whoever moderates the discussion, who see the account as always.
     *
     * @param  list<Post>  $posts
     * @return array<string, WriterNameDTO>
     */
    private function unnamedWriters(array $posts, ?Authenticatable $reader, ?string $readerId, bool $moderates): array
    {
        if ($moderates) {
            return [];
        }

        $writers = [];
        foreach ($posts as $post) {
            if ($readerId === null || $post->authorId() !== $readerId) {
                $writers[$post->id()->value] = new PostWriterDTO($post->authorId(), $post->characterId()?->value, $post->spokeAsRemovedCharacter());
            }
        }

        return array_filter(
            $this->writerNames->namesFor($writers, $reader),
            static fn (WriterNameDTO $name): bool => ! $name->isPublicName,
        );
    }

    /**
     * The one rule that says a post's writer is not shown: the post speaks as a shared character
     * the reader sees, and the reader neither wrote it, nor moderates the discussion, nor
     * moderates that character. `present()` and the filter by author both ask it.
     */
    private function hidesWriter(bool $own, bool $moderatesDiscussion, ?Character $seen, bool $moderatesCharacter): bool
    {
        return ! $own && ! $moderatesDiscussion && $seen instanceof Character && $seen->isNpc() && ! $moderatesCharacter;
    }

    /**
     * Whether one person's messages, filtered by their account (?autor=), leave out those they
     * wrote as a personal character or as one deleted since: when they have no public name and
     * the reader is neither them nor a moderator, for those posts never name the account
     * (CharacterWriterNames), and listing them under it would.
     */
    public function hidesCharacterPostsOf(string $authorId, bool $own, bool $moderatesDiscussion): bool
    {
        return ! $own && ! $moderatesDiscussion && ! $this->writerNames->hasPublicName($authorId);
    }

    /**
     * Which of these characters hide the writer of a post from this reader (see `hidesWriter()`).
     *
     * @param  bool  $own  whether the reader wrote the posts asked about
     * @param  bool  $moderatesDiscussion  whether the reader moderates the discussion
     * @param  list<string>  $ids  character ids
     * @return list<string> of those ids
     */
    public function charactersHidingTheirWriter(?Authenticatable $reader, bool $own, bool $moderatesDiscussion, array $ids): array
    {
        $hiding = [];
        foreach ($this->characters->findMany(array_map(CharacterId::fromString(...), array_values(array_unique($ids)))) as $id => $character) {
            $seen = $this->characterRules->maySee($reader, $character) ? $character : null;
            if ($this->hidesWriter($own, $moderatesDiscussion, $seen, $this->characterRules->moderates($reader, $character))) {
                $hiding[] = (string) $id;
            }
        }

        return $hiding;
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
