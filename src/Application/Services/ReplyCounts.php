<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostReferenceRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * How many visible posts reply to each post of a page (point at it with «#N» or quote it),
 * counting only replies in discussions the reader may read: one query for the page, and each
 * discussion those replies are in is looked up once.
 */
final readonly class ReplyCounts
{
    public function __construct(
        private PostReferenceRepositoryInterface $references,
        private DiscussionRepositoryInterface $discussions,
        private ForumAccessInterface $access,
    ) {}

    /**
     * @param  list<Post>  $posts
     * @return array<string, int> by post id; posts without replies are left out
     */
    public function countsFor(array $posts, ?Authenticatable $reader): array
    {
        $visible = array_values(array_map(static fn (Post $post): string => $post->id()->value, array_filter($posts, static fn (Post $post): bool => $post->isVisible())));
        if ($visible === []) {
            return [];
        }

        /** @var array<string, bool> $readable */
        $readable = [];
        $counts = [];
        foreach ($this->references->countsFor($visible) as $postId => $byDiscussion) {
            foreach ($byDiscussion as $discussionId => $replies) {
                $readable[$discussionId] ??= $this->readable($reader, $discussionId);
                if ($readable[$discussionId]) {
                    $counts[$postId] = ($counts[$postId] ?? 0) + $replies;
                }
            }
        }

        return $counts;
    }

    private function readable(?Authenticatable $reader, string $discussionId): bool
    {
        $discussion = $this->discussions->find(DiscussionId::fromString($discussionId));

        return $discussion instanceof Discussion && $this->access->canRead($reader, $discussion);
    }
}
