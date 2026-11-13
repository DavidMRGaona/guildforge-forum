<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\ForumLiveSignalsInterface;
use Modules\Forum\Application\Contracts\ForumNoticesInterface;
use Modules\Forum\Application\Contracts\PostLikesInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\DTOs\PostLikesDTO;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Likes: one per person and post, taken back with another click. Anyone signed in who reads a
 * visible post may like it, also in a closed discussion or a read-only space, except its own
 * author, the posts of the system account and anything in a hidden discussion (posts awaiting
 * approval are not visible either), and nobody suspended in the forum. The author hears about a
 * new like on the site.
 */
final readonly class PostLikeService
{
    /** How many names a post shows of who liked it; the rest are counted */
    public const int NAMES = 10;

    public function __construct(
        private PostRepositoryInterface $posts,
        private DiscussionRepositoryInterface $discussions,
        private ForumAccessInterface $access,
        private PostLikesInterface $likes,
        private AccountDirectoryInterface $accounts,
        private SystemAccountInterface $systemAccount,
        private ForumNoticesInterface $notices,
        private ForumSuspensions $suspensions,
        private ForumLiveSignalsInterface $live,
    ) {}

    /**
     * @throws PostNotFoundException when the person may not read it
     * @throws ForumActionDeniedException for their own post, the system account's or one not visible
     */
    public function like(Authenticatable $user, PostId $postId): PostLikesDTO
    {
        [$post, $discussion] = $this->readable($user, $postId);
        if (! $this->canLike($user, $post, $discussion)) {
            throw ForumActionDeniedException::because('like this post');
        }

        $likerId = (string) ForumBrowser::accountId($user);
        if ($this->likes->add($postId, $likerId, CarbonImmutable::now())) {
            $this->notices->postLiked($postId, $likerId);
            $this->live->postChanged($post);
        }

        return $this->of($user, $postId);
    }

    /**
     * @throws PostNotFoundException when the person may not read it
     */
    public function unlike(Authenticatable $user, PostId $postId): PostLikesDTO
    {
        [$post] = $this->readable($user, $postId);
        $this->likes->remove($postId, (string) ForumBrowser::accountId($user));
        $this->live->postChanged($post);

        return $this->of($user, $postId);
    }

    public function canLike(?Authenticatable $user, Post $post, Discussion $discussion): bool
    {
        $userId = ForumBrowser::accountId($user);
        $authorId = $post->authorId();

        return $userId !== null
            && $post->isVisible()
            && ! $discussion->isHidden()
            && $authorId !== null
            && $authorId !== $userId
            && ! $this->systemAccount->isSystemAccount($authorId)
            && $this->access->canRead($user, $discussion)
            // Someone suspended in the forum reads, but does not like
            && ! $this->suspensions->isBarred($user);
    }

    /**
     * The likes of the posts of a page, by post id; posts without likes get none().
     *
     * @param  list<Post>  $posts
     * @return array<string, PostLikesDTO>
     */
    public function forPosts(?Authenticatable $viewer, array $posts): array
    {
        $found = $this->likes->of(array_map(static fn (Post $post): PostId => $post->id(), $posts), ForumBrowser::accountId($viewer), self::NAMES);

        $likes = [];
        foreach ($posts as $post) {
            $likes[$post->id()->value] = $found[$post->id()->value] ?? PostLikesDTO::none();
        }

        return $likes;
    }

    /**
     * The names to show for each post's latest likes, looked up once for the whole page.
     *
     * @param  array<string, PostLikesDTO>  $likes
     * @return array<string, string> by account id
     */
    public function namesOf(array $likes): array
    {
        $ids = [];
        foreach ($likes as $summary) {
            array_push($ids, ...$summary->latestAccountIds);
        }

        return $ids === [] ? [] : $this->accounts->namesOf(array_values(array_unique($ids)));
    }

    private function of(Authenticatable $user, PostId $postId): PostLikesDTO
    {
        return $this->likes->of([$postId], ForumBrowser::accountId($user), self::NAMES)[$postId->value] ?? PostLikesDTO::none();
    }

    /**
     * @return array{Post, Discussion}
     *
     * @throws PostNotFoundException
     */
    private function readable(Authenticatable $user, PostId $postId): array
    {
        $post = $this->posts->find($postId);
        $discussion = $post instanceof Post ? $this->discussions->find($post->discussionId()) : null;

        if (! $post instanceof Post || ! $discussion instanceof Discussion || ! $this->access->canRead($user, $discussion)) {
            throw PostNotFoundException::withId($postId->value);
        }

        return [$post, $discussion];
    }
}
