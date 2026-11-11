<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\DTOs\PostNoticeDTO;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Whether a notice about a post may still reach a person, checked when it is delivered (not
 * when it was queued, since access, the post and the account may change meanwhile): an account
 * that can be reached (neither deactivated, anonymized nor the system account), a post still
 * visible and a discussion the person may read now. Every answer fails closed.
 */
final readonly class PostNoticeContext
{
    public const int EXCERPT_LENGTH = 200;

    public function __construct(
        private PostRepositoryInterface $posts,
        private DiscussionRepositoryInterface $discussions,
        private CategoryRepositoryInterface $categories,
        private ForumAccessInterface $access,
        private AccountDirectoryInterface $accounts,
        private ForumUrlsInterface $urls,
    ) {}

    /**
     * What the notice shows, or null when it must not reach the person.
     */
    public function for(object $notifiable, PostId $postId): ?PostNoticeDTO
    {
        $recipientId = $notifiable instanceof Authenticatable ? ForumBrowser::accountId($notifiable) : null;
        if (! $notifiable instanceof Authenticatable || $recipientId === null || $this->accounts->mentionable([$recipientId]) === []) {
            return null;
        }

        $post = $this->posts->find($postId);
        $number = $post?->number()?->value;
        if (! $post instanceof Post || ! $post->isVisible() || $number === null) {
            return null;
        }

        $discussion = $this->discussionOf($post);
        $category = $discussion instanceof Discussion ? $this->categories->find($discussion->categoryId()) : null;
        if (! $discussion instanceof Discussion || ! $category instanceof Category || ! $this->access->canRead($notifiable, $discussion)) {
            return null;
        }

        $authorId = $post->authorId();

        return new PostNoticeDTO(
            post: $post,
            discussion: $discussion,
            category: $category,
            recipientId: $recipientId,
            authorName: $authorId === null ? null : ($this->accounts->namesOf([$authorId])[$authorId] ?? null),
            excerpt: Str::limit(trim((string) preg_replace('/\s+/u', ' ', $post->body()->plainText)), self::EXCERPT_LENGTH),
            path: $this->urls->discussion($discussion, $number),
            url: $this->urls->discussion($discussion, $number, absolute: true),
        );
    }

    /**
     * The discussion a post is in, when both still exist.
     */
    public function discussionOfPost(PostId $postId): ?Discussion
    {
        $post = $this->posts->find($postId);

        return $post instanceof Post ? $this->discussionOf($post) : null;
    }

    private function discussionOf(Post $post): ?Discussion
    {
        return $this->discussions->find($post->discussionId());
    }
}
