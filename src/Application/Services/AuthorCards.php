<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Profiles\Services\ProfileCardServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Conversations\BlockService;
use Modules\Forum\Application\DTOs\AuthorCardDTO;
use Modules\Forum\Application\DTOs\PostContentDTO;
use Modules\Forum\Application\DTOs\WriterNameDTO;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\MemberTitleRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PostNumber;

/**
 * The card of a post's author (forum#134): who wrote it, as the reader may know them. It starts
 * from the post, never from an account: only a post the reader may see, and only when the page
 * names its writer to them, so the card never tells who is behind a shared character
 * (`authorHidden`) nor names an account the post does not name (no public name, forum#139). The
 * forum's own account has no card either. Anything else is not found, as a missing post is.
 *
 * The profile is the core's card for the reader (ProfileCardServiceInterface, through the
 * profile's access rule); the counts are those of the forum's own rules for the reader: the
 * messages its profile section counts (ProfileActivity, readable categories only) and those the
 * filter by author lists in the discussion (ConversationReader).
 */
final readonly class AuthorCards
{
    public function __construct(
        private PostRepositoryInterface $posts,
        private DiscussionRepositoryInterface $discussions,
        private ForumAccessInterface $access,
        private PostContentPresenter $contents,
        private SystemAccountInterface $systemAccount,
        private ProfileCardServiceInterface $cards,
        private ProfileActivity $activity,
        private ConversationReader $reader,
        private MemberTitleRepositoryInterface $titles,
        private BlockService $blocks,
        private AccountDirectoryInterface $accounts,
    ) {}

    /**
     * @throws PostNotFoundException when the reader may not see the post or its writer
     */
    public function of(?Authenticatable $reader, PostId $postId): AuthorCardDTO
    {
        [$post, $discussion] = $this->seen($reader, $postId);
        $content = $this->contents->present([$post], $discussion, $reader)[0];
        $authorId = $this->namedWriter($post, $content);
        if ($authorId === null) {
            throw PostNotFoundException::withId($postId->value);
        }

        try {
            $topicCount = $this->reader->countByAuthor($reader, $discussion->id(), $authorId);
        } catch (DiscussionNotFoundException) {
            throw PostNotFoundException::withId($postId->value);
        }

        return new AuthorCardDTO(
            authorId: $authorId,
            discussion: $discussion,
            profile: $this->cards->card($authorId, $reader),
            title: $this->titles->titlesOf([$authorId])[$authorId] ?? null,
            postCount: $this->activity->postCount($authorId, $reader),
            topicCount: $topicCount,
            blocked: $this->blockedState($reader, $authorId),
        );
    }

    /**
     * The post and its discussion when the reader sees the post on the discussion's page: a
     * published one (shown as it is, hidden or deleted) in a discussion they may read, or one
     * awaiting approval for its author and moderation.
     *
     * @return array{Post, Discussion}
     *
     * @throws PostNotFoundException
     */
    private function seen(?Authenticatable $reader, PostId $postId): array
    {
        $post = $this->posts->find($postId);
        $discussion = $post instanceof Post ? $this->discussions->find($post->discussionId()) : null;
        if (! $post instanceof Post || ! $discussion instanceof Discussion || ! $this->access->canRead($reader, $discussion)) {
            throw PostNotFoundException::withId($postId->value);
        }

        $readerId = ForumBrowser::accountId($reader);
        $published = $post->number() instanceof PostNumber && $post->status() !== PostStatus::Pending;
        $awaiting = $post->status() === PostStatus::Pending
            && $readerId !== null
            && ($post->authorId() === $readerId || $this->access->canModerate($reader, $discussion));
        if (! $published && ! $awaiting) {
            throw PostNotFoundException::withId($postId->value);
        }

        return [$post, $discussion];
    }

    /**
     * The account the post names as its writer to the reader, by the rule the discussion page
     * draws it with (ForumPresenter::posts()), or null when it names none.
     */
    private function namedWriter(Post $post, PostContentDTO $content): ?string
    {
        $authorId = $post->authorId();
        if ($authorId === null || $content->authorHidden || $content->unnamedWriter instanceof WriterNameDTO || $this->systemAccount->isSystemAccount($authorId)) {
            return null;
        }

        return $authorId;
    }

    /**
     * Whether the reader ignores the person, or null when «Ignorar» does not apply: for a guest,
     * on their own post, or for an account that no longer exists (BlockService refuses them).
     */
    private function blockedState(?Authenticatable $reader, string $authorId): ?bool
    {
        $readerId = ForumBrowser::accountId($reader);
        if (! $reader instanceof Authenticatable || $readerId === null || $readerId === $authorId || $this->accounts->namesOf([$authorId]) === []) {
            return null;
        }

        return $this->blocks->hasBlocked($reader, $authorId);
    }
}
