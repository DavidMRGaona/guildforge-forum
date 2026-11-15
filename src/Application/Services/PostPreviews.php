<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\DTOs\PostPreviewDTO;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostReferenceRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Posts as references open them, and the posts that reply to one. Only a visible post in a
 * discussion the reader may read is shown; anything else is not found, as a missing post is.
 * A long post is shown without its images and embeds, which say how many were left out.
 */
final readonly class PostPreviews
{
    /** Longer than this (in characters of plain text), a post is shown without its media */
    public const int LONG_POST = 600;

    public const int REPLIES_PER_PAGE = 20;

    public function __construct(
        private PostRepositoryInterface $posts,
        private DiscussionRepositoryInterface $discussions,
        private PostReferenceRepositoryInterface $references,
        private ForumAccessInterface $access,
        private PostContentPresenter $contents,
        private PostRenderer $renderer,
    ) {}

    /**
     * @throws PostNotFoundException
     */
    public function preview(?Authenticatable $reader, PostId $postId): PostPreviewDTO
    {
        [$post, $discussion] = $this->readable($reader, $postId);

        return $this->previewOf($post, $discussion, $reader);
    }

    /**
     * The visible replies the reader may read, oldest first.
     *
     * @return array{previews: list<PostPreviewDTO>, hasMore: bool}
     *
     * @throws PostNotFoundException when the post itself may not be read
     */
    public function replies(?Authenticatable $reader, PostId $postId, int $page): array
    {
        $this->readable($reader, $postId);

        /** @var array<string, Discussion|null> $discussions */
        $discussions = [];
        $readable = [];
        foreach ($this->references->visibleSourcesOf($postId) as $source) {
            $id = $source['discussionId'];
            if (! array_key_exists($id, $discussions)) {
                $discussion = $this->discussions->find(DiscussionId::fromString($id));
                $discussions[$id] = $discussion instanceof Discussion && $this->access->canRead($reader, $discussion) ? $discussion : null;
            }
            if ($discussions[$id] instanceof Discussion) {
                $readable[] = $source;
            }
        }

        $shown = array_slice($readable, (max(1, $page) - 1) * self::REPLIES_PER_PAGE, self::REPLIES_PER_PAGE);
        $posts = [];
        foreach ($this->posts->findMany(array_map(static fn (array $source): PostId => PostId::fromString($source['postId']), $shown)) as $post) {
            $posts[$post->id()->value] = $post;
        }

        $previews = [];
        foreach ($shown as $source) {
            $post = $posts[$source['postId']] ?? null;
            $discussion = $discussions[$source['discussionId']] ?? null;
            if ($post instanceof Post && $discussion instanceof Discussion) {
                $previews[] = $this->previewOf($post, $discussion, $reader);
            }
        }

        return ['previews' => $previews, 'hasMore' => count($readable) > max(1, $page) * self::REPLIES_PER_PAGE];
    }

    /**
     * @return array{Post, Discussion}
     *
     * @throws PostNotFoundException
     */
    private function readable(?Authenticatable $reader, PostId $postId): array
    {
        $post = $this->posts->find($postId);
        $discussion = $post instanceof Post && $post->isVisible() ? $this->discussions->find($post->discussionId()) : null;
        if (! $post instanceof Post || ! $discussion instanceof Discussion || ! $this->access->canRead($reader, $discussion)) {
            throw PostNotFoundException::withId($postId->value);
        }

        return [$post, $discussion];
    }

    private function previewOf(Post $post, Discussion $discussion, ?Authenticatable $reader): PostPreviewDTO
    {
        $long = mb_strlen($post->body()->plainText) > self::LONG_POST;
        $media = $long ? $this->renderer->mediaCounts($post->body()) : ['images' => 0, 'embeds' => 0];

        return new PostPreviewDTO(
            post: $post,
            discussion: $discussion,
            // A box is not read again after revealing a roll: it offers no «Revelar»
            content: $this->contents->present([$post], $discussion, $reader, withoutMedia: $long, offerReveal: false)[0],
            truncated: $long,
            hiddenImages: $media['images'],
            hiddenEmbeds: $media['embeds'],
        );
    }
}
