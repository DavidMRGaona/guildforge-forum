<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\DTOs\StartDiscussionDTO;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Exceptions\CharacterUnavailableException;
use Modules\Forum\Domain\Exceptions\DiscussionNotStartedException;
use Modules\Forum\Domain\Exceptions\InvalidDiscussionException;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Exceptions\PostingTooFastException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Starting a discussion from the forum: a title, a category the person may write in (never one
 * of the categories publications fill), tags already used in the forum, and the first post.
 * Only moderators of the category start it pinned or closed. Starting one counts against the
 * discussions-per-hour limit as well as the posts one. In a category that asks newcomers for
 * approval (ApprovalRule) it waits, with its first post, for a moderator. Once it is saved and
 * shown, a discussion guests can read may be announced (DiscussionAnnouncer).
 */
final readonly class DiscussionStarter
{
    public const int MIN_TITLE_LENGTH = 3;

    public const int MAX_TITLE_LENGTH = 120;

    public const int MAX_TOPICS = 5;

    public function __construct(
        private ForumBrowser $browser,
        private PostBodyFactory $bodies,
        private PostingService $posting,
        private ForumWriter $writer,
        private DiscussionRepositoryInterface $discussions,
        private DiscussionAnnouncer $announcer,
        private DiscussionReadsInterface $reads,
        private FollowService $follows,
        private ApprovalRule $approval,
        private PostSpeakers $speakers,
    ) {}

    /**
     * The categories the person may start discussions in.
     *
     * @return list<CategoryAccessDTO>
     */
    public function categoriesFor(?Authenticatable $author): array
    {
        return array_values(array_filter(
            $this->browser->readableCategories($author),
            static fn (CategoryAccessDTO $access): bool => $access->canStartDiscussions(),
        ));
    }

    /**
     * The tags a new discussion may carry: the game-system tags discussions the person can read
     * already have.
     *
     * @return list<DiscussionTopic>
     */
    public function topicsFor(?Authenticatable $author): array
    {
        return $this->browser->topics($this->browser->readableCategories($author));
    }

    /**
     * @throws DiscussionNotStartedException
     * @throws InvalidDiscussionException for a title out of bounds
     * @throws InvalidDocumentException
     * @throws PostingTooFastException
     * @throws CharacterUnavailableException when the first post would speak as a character the author may not use there
     */
    public function start(Authenticatable $author, StartDiscussionDTO $input): Discussion
    {
        $access = null;
        foreach ($this->categoriesFor($author) as $candidate) {
            if ($candidate->category->id()->value === strtolower($input->categoryId)) {
                $access = $candidate;
            }
        }

        if (! $access instanceof CategoryAccessDTO) {
            throw DiscussionNotStartedException::categoryNotWritable($input->categoryId);
        }

        if (($input->pinned || $input->closed) && ! $access->canModerate) {
            throw DiscussionNotStartedException::notModerator();
        }

        $title = new DiscussionTitle($this->checkedTitle($input->title));
        $topics = $this->checkedTopics($author, $input->topicKeys);
        $body = $this->bodies->fromInput($input->document, $author);
        $speaker = $this->speakers->forNewPost($author, $input->characterId, $access->category, startsDiscussion: true);
        $this->posting->countDiscussion($author);
        $this->posting->countPost($author);
        $needsApproval = $this->approval->needsApproval($author, $access->category);

        $authorId = ForumBrowser::accountId($author);
        $discussion = Discussion::start(DiscussionId::generate(), $access->category->id(), $authorId, $title, CarbonImmutable::now());
        if ($input->pinned) {
            $discussion->pin();
        }
        if ($input->closed) {
            $discussion->close();
        }

        $firstPost = null;
        DB::transaction(function () use ($discussion, $body, $topics, $needsApproval, $speaker, &$firstPost): void {
            $firstPost = $this->writer->startDiscussion($discussion, PostId::generate(), $body, $needsApproval, $speaker);
            if ($topics !== []) {
                $this->discussions->replaceTopics($discussion->id(), $topics);
            }
        });

        // Its author has read what they wrote, and follows it; one awaiting approval is announced once approved
        $this->follows->followAfterPosting((string) $authorId, $discussion->id());
        if (! $discussion->isAwaitingApproval()) {
            $this->reads->advance((string) $authorId, $discussion->id(), 1, $discussion->createdAt());
            // What was saved, with its dice thrown
            $this->announcer->announce($discussion, $access->category, $firstPost?->body() ?? $body, $topics);
        }

        return $discussion;
    }

    /**
     * @throws InvalidDiscussionException
     */
    private function checkedTitle(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $length = mb_strlen($title);

        if ($length === 0) {
            throw InvalidDiscussionException::blankTitle();
        }

        if ($length < self::MIN_TITLE_LENGTH || $length > self::MAX_TITLE_LENGTH) {
            throw InvalidDiscussionException::titleOutOfBounds(self::MIN_TITLE_LENGTH, self::MAX_TITLE_LENGTH);
        }

        return $title;
    }

    /**
     * @param  list<string>  $keys
     * @return list<DiscussionTopic>
     *
     * @throws DiscussionNotStartedException
     */
    private function checkedTopics(Authenticatable $author, array $keys): array
    {
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return [];
        }

        if (count($keys) > self::MAX_TOPICS) {
            throw DiscussionNotStartedException::unknownTopic($keys[self::MAX_TOPICS]);
        }

        $known = [];
        foreach ($this->topicsFor($author) as $topic) {
            $known[$topic->key] = $topic;
        }

        $topics = [];
        foreach ($keys as $key) {
            $topics[] = $known[$key] ?? throw DiscussionNotStartedException::unknownTopic($key);
        }

        return $topics;
    }
}
