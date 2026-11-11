<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\DTOs\PostContentDTO;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostStatus;

/**
 * What each post of a discussion shows to a reader. A post its author deleted keeps its place
 * as «Mensaje borrado» and a hidden one as «Mensaje oculto»; their moderators still read them,
 * and a hidden post's author does too. The pages decide which posts to list (pending ones only
 * to their author and moderators).
 */
final readonly class PostContentPresenter
{
    public function __construct(
        private ForumAccessInterface $access,
        private PostingService $posting,
        private PostRenderer $renderer,
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

        $shown = array_filter($posts, fn (Post $post): bool => $this->showsContent($post, $readerId, $moderates));
        $html = array_combine(
            array_map(static fn (Post $post): string => $post->id()->value, $shown),
            $this->renderer->toHtml(array_values(array_map(static fn (Post $post) => $post->body(), $shown)), $reader),
        );

        return array_map(function (Post $post) use ($html, $readerId, $canPost): PostContentDTO {
            $own = $readerId !== null && $post->authorId() === $readerId;
            $editable = $own && $canPost && $post->status() !== PostStatus::Deleted;

            return new PostContentDTO(
                id: $post->id()->value,
                number: $post->number()?->value,
                authorId: $post->authorId(),
                status: $post->status(),
                html: $html[$post->id()->value] ?? null,
                placeholder: $this->placeholder($post->status()),
                editedAt: $post->editedAt(),
                canEdit: $editable,
                canDelete: $editable && $post->status() !== PostStatus::Pending,
            );
        }, $posts);
    }

    private function showsContent(Post $post, ?string $readerId, bool $moderates): bool
    {
        return match ($post->status()) {
            PostStatus::Visible => true,
            PostStatus::Hidden, PostStatus::Pending => $moderates || ($readerId !== null && $post->authorId() === $readerId),
            PostStatus::Deleted => $moderates,
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
