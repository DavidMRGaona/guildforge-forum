<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Content\ReferenceLinking;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * Turns «#N» in a post being written into references to posts of its discussion, when whoever
 * writes may read it; pending, hidden and deleted posts keep their number as text. The access is
 * checked here too, not only by whoever saves the post: an edit refused later must not have
 * told, by its answer, which posts a discussion they may not read has.
 */
final readonly class PostReferenceLinker
{
    public function __construct(
        private PostRepositoryInterface $posts,
        private DiscussionRepositoryInterface $discussions,
        private ForumAccessInterface $access,
    ) {}

    /**
     * Without a discussion (its first post) nothing is turned, but the editor's labels still go.
     *
     * @param  array<string, mixed>  $document  a document that passed the schema
     * @return array<string, mixed>
     */
    public function link(array $document, ?DiscussionId $discussionId, Authenticatable $author): array
    {
        return ReferenceLinking::link($document, function (array $numbers) use ($discussionId, $author): array {
            $discussion = $discussionId instanceof DiscussionId ? $this->discussions->find($discussionId) : null;
            if (! $discussion instanceof Discussion || ! $this->access->canRead($author, $discussion)) {
                return [];
            }

            $ids = [];
            foreach ($this->posts->withNumbers($discussionId, $numbers) as $post) {
                $number = $post->number()?->value;
                if ($number !== null && $post->isVisible()) {
                    $ids[$number] = $post->id()->value;
                }
            }

            return $ids;
        });
    }
}
