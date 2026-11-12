<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\MentionCandidatesInterface;
use Modules\Forum\Application\Contracts\PostRateLimiterInterface;
use Modules\Forum\Application\DTOs\AccountSummaryDTO;
use Modules\Forum\Application\DTOs\PostVersionDTO;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\CharacterUnavailableException;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Exceptions\PostingTooFastException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRevisionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * What people do with their posts from the editor: reply, edit and delete their own, and, for
 * moderators, read a post's versions. Each action checks the category's access rules first;
 * what the reader may not read answers as if it did not exist.
 */
final readonly class PostingService
{
    public const int MENTION_CANDIDATES = 8;

    public function __construct(
        private DiscussionRepositoryInterface $discussions,
        private PostRepositoryInterface $posts,
        private PostRevisionRepositoryInterface $revisions,
        private ForumAccessInterface $access,
        private PostBodyFactory $bodies,
        private PostRateLimiterInterface $rateLimiter,
        private ForumWriter $writer,
        private PostRenderer $renderer,
        private AccountDirectoryInterface $accounts,
        private MentionCandidatesInterface $mentionCandidates,
        private DiscussionReadsInterface $reads,
        private FollowService $follows,
        private CategoryRepositoryInterface $categories,
        private ApprovalRule $approval,
        private PostSpeakers $speakers,
        private CharacterRepositoryInterface $characters,
    ) {}

    /**
     * @throws DiscussionNotFoundException when the author may not read it
     * @throws ForumActionDeniedException when the author may not write in it, or it is closed
     * @throws InvalidDocumentException
     * @throws PostingTooFastException
     * @throws CharacterUnavailableException when it would speak as a character the author may not use there
     */
    public function reply(Authenticatable $author, DiscussionId $discussionId, mixed $document, ?string $characterId = null): Post
    {
        $discussion = $this->readableDiscussion($author, $discussionId);
        $this->assertCanPost($author, $discussion);

        $body = $this->bodies->fromInput($document, $author);
        $category = $this->categories->find($discussion->categoryId());
        if (! $category instanceof Category) {
            throw DiscussionNotFoundException::withId($discussionId->value);
        }
        $speaker = $this->speakers->forNewPost($author, $characterId, $category);
        $this->countPost($author);

        $now = CarbonImmutable::now();
        $post = $this->writer->reply($discussionId, PostId::generate(), $this->accountId($author), $body, $now, needsApproval: $this->approval->needsApproval($author, $category), characterId: $speaker);

        // Whoever replies has read the discussion up to their reply, and follows it from now on
        $number = $post->number()?->value;
        if ($number !== null) {
            $this->reads->advance($this->accountId($author), $discussionId, $number, $now);
        }
        $this->follows->followAfterPosting($this->accountId($author), $discussionId);

        return $post;
    }

    /**
     * Replaces the body of one of the author's posts, keeping what it said in its history.
     *
     * @param  bool  $speakerGiven  whether the editor said who it speaks as ($characterId, null out of character); otherwise it keeps its own
     *
     * @throws PostNotFoundException when the author may not read it
     * @throws ForumActionDeniedException when it is someone else's, or the author may no longer post there
     * @throws InvalidDocumentException
     * @throws InvalidPostException once it was deleted
     * @throws CharacterUnavailableException when it would switch to a character the author may not use there
     */
    public function edit(Authenticatable $author, PostId $postId, mixed $document, bool $speakerGiven = false, ?string $characterId = null): Post
    {
        $discussion = $this->ownPostDiscussion($author, $postId);
        $this->assertCanPost($author, $discussion);

        $body = $this->bodies->fromInput($document, $author);
        $post = $this->posts->findOrFail($postId);
        $category = $this->categories->find($discussion->categoryId());
        if (! $category instanceof Category) {
            throw PostNotFoundException::withId($postId->value);
        }
        $speaker = $this->speakers->forEdit($author, $post, $category, $speakerGiven, $characterId);

        return $this->writer->editPost($postId, $body, $this->accountId($author), CarbonImmutable::now(), $speaker);
    }

    /**
     * Deletes one of the author's posts: it keeps its place and readers see «Mensaje borrado».
     *
     * @throws PostNotFoundException when the author may not read it
     * @throws ForumActionDeniedException when it is someone else's, or the author may no longer post there
     * @throws InvalidPostException once it was deleted
     */
    public function deleteOwn(Authenticatable $author, PostId $postId): Post
    {
        $discussion = $this->ownPostDiscussion($author, $postId);
        $this->assertCanPost($author, $discussion);

        return $this->writer->deletePostByAuthor($postId, CarbonImmutable::now());
    }

    /**
     * Whether the post is the person's own (whatever they may do with it).
     */
    public function owns(Authenticatable $user, PostId $postId): bool
    {
        $authorId = $this->posts->find($postId)?->authorId();

        return $authorId !== null && $authorId === $this->accountId($user);
    }

    /**
     * Every version of a post, oldest first and the current one last, for its moderators.
     *
     * @return list<PostVersionDTO>
     *
     * @throws PostNotFoundException when the person may not read it
     * @throws ForumActionDeniedException unless they moderate it
     */
    public function versions(Authenticatable $moderator, PostId $postId): array
    {
        [$post, $discussion] = $this->readablePost($moderator, $postId);
        if (! $this->access->canModerate($moderator, $discussion)) {
            throw ForumActionDeniedException::because('see the versions of a post');
        }

        $revisions = $this->revisions->ofPost($postId);
        $bodies = [...array_map(static fn ($revision): PostBody => $revision->body, $revisions), $post->body()];
        $characterIds = [...array_map(static fn ($revision): ?CharacterId => $revision->characterId, $revisions), $post->characterId()];
        $characters = $this->characters->findMany(array_values(array_filter($characterIds)));
        $speakers = array_map(static fn (?CharacterId $id): ?string => $id instanceof CharacterId ? ($characters[$id->value] ?? null)?->name() : null, $characterIds);
        $html = $this->renderer->toHtml($bodies, $moderator, $speakers);

        // Version 1 is the author's; each later one is by whoever made the edit before it
        $writers = [[$post->authorId(), $post->publishedAt() ?? $post->createdAt()]];
        foreach ($revisions as $revision) {
            $writers[] = [$revision->editorId(), $revision->replacedAt];
        }

        $names = $this->accounts->namesOf(array_values(array_unique(array_filter(array_column($writers, 0), is_string(...)))));

        $versions = [];
        foreach ($writers as $index => [$writerId, $writtenAt]) {
            $versions[] = new PostVersionDTO(
                number: $index + 1,
                html: $html[$index],
                authorName: $writerId === null ? null : ($names[$writerId] ?? null),
                writtenAt: $writtenAt,
                isCurrent: $index === count($writers) - 1,
                characterName: $speakers[$index],
            );
        }

        return $versions;
    }

    /**
     * Whom the person may mention in the discussion.
     *
     * @return list<AccountSummaryDTO>
     *
     * @throws DiscussionNotFoundException when the person may not read it
     */
    public function mentionCandidates(Authenticatable $user, DiscussionId $discussionId, string $query): array
    {
        $discussion = $this->readableDiscussion($user, $discussionId);
        $query = trim($query);

        return $query === '' ? [] : $this->mentionCandidates->search($discussion, $query, self::MENTION_CANDIDATES);
    }

    /**
     * Counts a post against the person's limit; whoever starts a discussion calls it too.
     *
     * @throws PostingTooFastException
     */
    public function countPost(Authenticatable $author): void
    {
        $wait = $this->rateLimiter->attempt($this->accountId($author));
        if ($wait !== null) {
            throw PostingTooFastException::retryIn($wait);
        }
    }

    /**
     * Counts a new discussion against the person's limit per hour.
     *
     * @throws PostingTooFastException
     */
    public function countDiscussion(Authenticatable $author): void
    {
        $wait = $this->rateLimiter->attemptDiscussion($this->accountId($author));
        if ($wait !== null) {
            throw PostingTooFastException::retryIn($wait, discussions: true);
        }
    }

    /**
     * Whether the person may post in the discussion (and so edit or delete their posts in it):
     * they write in its category, and it is open or they moderate it.
     */
    public function canPost(?Authenticatable $user, Discussion $discussion): bool
    {
        // Awaiting approval it takes no posts: the first one would take its number
        return $user instanceof Authenticatable
            && ! $discussion->isAwaitingApproval()
            && $this->access->canWrite($user, $discussion)
            && ($discussion->acceptsReplies() || $this->access->canModerate($user, $discussion));
    }

    /**
     * @throws ForumActionDeniedException
     */
    public function assertCanPost(Authenticatable $author, Discussion $discussion): void
    {
        if (! $this->canPost($author, $discussion)) {
            throw ForumActionDeniedException::because('post in this discussion');
        }
    }

    /**
     * @throws DiscussionNotFoundException
     */
    private function readableDiscussion(?Authenticatable $user, DiscussionId $discussionId): Discussion
    {
        $discussion = $this->discussions->find($discussionId);
        if (! $discussion instanceof Discussion || ! $this->access->canRead($user, $discussion)) {
            throw DiscussionNotFoundException::withId($discussionId->value);
        }

        return $discussion;
    }

    /**
     * A post the person may read: a visible one in a discussion they read, or their own, or
     * any in a discussion they moderate.
     *
     * @return array{Post, Discussion}
     *
     * @throws PostNotFoundException
     */
    private function readablePost(Authenticatable $user, PostId $postId): array
    {
        $post = $this->posts->find($postId);
        $discussion = $post instanceof Post ? $this->discussions->find($post->discussionId()) : null;

        if (! $post instanceof Post || ! $discussion instanceof Discussion || ! $this->access->canRead($user, $discussion)) {
            throw PostNotFoundException::withId($postId->value);
        }

        if (! $post->isVisible() && $post->authorId() !== $this->accountId($user) && ! $this->access->canModerate($user, $discussion)) {
            throw PostNotFoundException::withId($postId->value);
        }

        return [$post, $discussion];
    }

    /**
     * @throws PostNotFoundException
     * @throws ForumActionDeniedException
     */
    private function ownPostDiscussion(Authenticatable $author, PostId $postId): Discussion
    {
        [$post, $discussion] = $this->readablePost($author, $postId);
        if ($post->authorId() === null || $post->authorId() !== $this->accountId($author)) {
            throw ForumActionDeniedException::because('change someone else\'s post');
        }

        return $discussion;
    }

    private function accountId(Authenticatable $user): string
    {
        $id = $user->getAuthIdentifier();

        return strtolower(is_scalar($id) ? (string) $id : '');
    }
}
