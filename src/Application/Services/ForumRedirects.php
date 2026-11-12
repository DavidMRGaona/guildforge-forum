<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\DTOs\RedirectTargetDTO;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\RedirectRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PostNumber;

/**
 * Where an address of a post or discussion that moderation moved leads now (split off, or
 * merged into another). A redirect names the post itself, so it follows it through later moves.
 * Whoever may not read where it went gets nothing: the address does not tell them its title.
 */
final readonly class ForumRedirects
{
    public function __construct(
        private DiscussionRepositoryInterface $discussions,
        private PostRepositoryInterface $posts,
        private RedirectRepositoryInterface $redirects,
        private ForumAccessInterface $access,
    ) {}

    /**
     * Where the address /foro/t/<number>[/<post number>] leads now; null when nothing of it
     * moved (or the reader may not read where it went).
     */
    public function resolve(?Authenticatable $reader, int $discussionNumber, ?int $postNumber): ?RedirectTargetDTO
    {
        $discussion = $this->discussions->findByNumber($discussionNumber);
        $merged = $discussion instanceof Discussion ? null : $this->redirects->mergedDiscussion($discussionNumber);
        $fromId = $discussion?->id() ?? $merged?->from;
        if (! $fromId instanceof DiscussionId) {
            return null;
        }

        if ($postNumber !== null) {
            $postId = $this->redirects->movedPost($fromId, $postNumber);
            $post = $postId instanceof PostId ? $this->posts->find($postId) : null;
            if ($post instanceof Post && $post->number() instanceof PostNumber) {
                $target = $this->discussions->find($post->discussionId());

                return $target instanceof Discussion && $this->access->canRead($reader, $target)
                    ? new RedirectTargetDTO($target, $post->number()->value)
                    : null;
            }
        }

        if ($discussion instanceof Discussion || ! $merged?->into instanceof DiscussionId) {
            return null;
        }

        $into = $this->discussions->find($merged->into);

        return $into instanceof Discussion && $this->access->canRead($reader, $into) ? new RedirectTargetDTO($into, null) : null;
    }
}
