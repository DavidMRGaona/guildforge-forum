<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Profiles\DTOs\ProfileCardDTO;
use App\Application\Profiles\Services\ProfileCardServiceInterface;
use App\Application\Services\EmailVerificationPolicyInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Conversations\BlockService;
use Modules\Forum\Application\DTOs\AuthorCardDTO;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostStatus;
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
 * (`authorHidden`) nor names an account the post does not name (no public name, forum#139), by
 * the rule the page draws it with (PostContentPresenter::namesWriter()). The forum's own account
 * has no card either. Anything else is not found, as a missing post is.
 *
 * The profile is the core's card for the reader (ProfileCardServiceInterface, through the
 * profile's access rule); the counts are those of the forum's own rules for the reader: the
 * messages its profile section counts (ProfileActivity, readable categories only), only with a
 * profile the reader may see, and those the filter by author lists in the discussion
 * (ConversationReader).
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
        private EmailVerificationPolicyInterface $verification,
    ) {}

    /**
     * @throws PostNotFoundException when the reader may not see the post or its writer
     */
    public function of(?Authenticatable $reader, PostId $postId): AuthorCardDTO
    {
        [$post, $discussion] = $this->seen($reader, $postId);
        $authorId = $post->authorId();
        if ($authorId === null || $this->systemAccount->isSystemAccount($authorId) || ! $this->contents->namesWriter($post, $discussion, $reader)) {
            throw PostNotFoundException::withId($postId->value);
        }

        $profile = $this->cards->card($authorId, $reader);

        return new AuthorCardDTO(
            authorId: $authorId,
            discussion: $discussion,
            profile: $profile,
            title: $this->titles->titlesOf([$authorId])[$authorId] ?? null,
            // Without a profile the card keeps what the post shows: no count of their own
            postCount: $profile instanceof ProfileCardDTO ? $this->activity->postCount($authorId, $reader) : null,
            topicCount: $this->reader->countByAuthor($reader, $discussion, $authorId),
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
     * Whether the reader ignores the person, or null when «Ignorar» does not apply: for a guest,
     * on their own post, for a reader who must verify their email first (`foro/bloqueos` refuses
     * them) or for an account that no longer exists (BlockService refuses it).
     */
    private function blockedState(?Authenticatable $reader, string $authorId): ?bool
    {
        $readerId = ForumBrowser::accountId($reader);
        if (! $reader instanceof Authenticatable || $readerId === null || $readerId === $authorId) {
            return null;
        }
        if ($this->verification->mustVerify($reader) || $this->accounts->namesOf([$authorId]) === []) {
            return null;
        }

        return $this->blocks->hasBlocked($reader, $authorId);
    }
}
