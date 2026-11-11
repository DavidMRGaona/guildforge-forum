<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Enums\CategoryFollowOrigin;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\Exceptions\CategoryNotFoundException;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Throwable;

/**
 * How each person follows discussions and categories:
 * - A discussion is «Seguir» (every reply), «Normal» (mentions and quotes) or «Silenciar»
 *   (nothing). Muting is the core's mute of the discussion, the same one a mail footer offers,
 *   so the forum, the mail and the profile always agree.
 * - Starting a discussion or replying follows it, unless the person turned that off or already
 *   chose something for it.
 * - Following a category brings its new discussions (and those of its subcategories).
 * Only what the person may read can be followed; the system account follows nothing.
 */
final readonly class FollowService
{
    public function __construct(
        private FollowsInterface $follows,
        private ForumAccessInterface $access,
        private CategoryAccessPolicy $policy,
        private DiscussionRepositoryInterface $discussions,
        private MailSubjectMuteServiceInterface $mutes,
        private SystemAccountInterface $systemAccount,
    ) {}

    public function levelOf(?Authenticatable $user, Discussion $discussion): FollowLevel
    {
        $userId = ForumBrowser::accountId($user);
        if (! $user instanceof Authenticatable || $userId === null) {
            return FollowLevel::Normal;
        }

        if ($this->isMuted($user, $discussion)) {
            return FollowLevel::Muted;
        }

        return $this->follows->discussionLevel($userId, $discussion->id()) === FollowLevel::Following ? FollowLevel::Following : FollowLevel::Normal;
    }

    /**
     * @throws DiscussionNotFoundException when the person may not read it
     */
    public function setLevel(Authenticatable $user, DiscussionId $discussionId, FollowLevel $level): FollowLevel
    {
        $discussion = $this->discussions->find($discussionId);
        $userId = ForumBrowser::accountId($user);
        if (! $discussion instanceof Discussion || $userId === null || $this->systemAccount->isSystemAccount($userId) || ! $this->access->canRead($user, $discussion)) {
            throw DiscussionNotFoundException::withId($discussionId->value);
        }

        $subject = ForumMailSubjects::discussion($discussion);
        if ($level === FollowLevel::Muted) {
            $this->mutes->mute($user, $subject);
        } else {
            $this->mutes->unmute($user, $subject->type, $subject->id);
        }

        $this->follows->setDiscussionLevel($userId, $discussionId, $level, CarbonImmutable::now());

        return $level;
    }

    /**
     * Follows the discussion its author just started or replied to, unless they turned that off
     * or chose something for it before.
     */
    public function followAfterPosting(string $authorId, DiscussionId $discussionId): void
    {
        if ($this->systemAccount->isSystemAccount($authorId) || ! $this->follows->autoFollows($authorId)) {
            return;
        }

        $this->follows->followUnlessChosen($authorId, $discussionId, CarbonImmutable::now());
    }

    public function followsCategory(?Authenticatable $user, Category $category): bool
    {
        $userId = ForumBrowser::accountId($user);

        return $userId !== null && $this->follows->followsCategory($userId, $category->id());
    }

    /**
     * @throws CategoryNotFoundException when the person may not read it
     */
    public function setCategoryFollow(Authenticatable $user, Category $category, bool $follow): void
    {
        $userId = ForumBrowser::accountId($user);
        if ($userId === null || $this->systemAccount->isSystemAccount($userId) || ! $this->policy->canView($user, $category)) {
            throw CategoryNotFoundException::withId($category->id()->value);
        }

        if ($follow) {
            $this->follows->followCategory($userId, $category->id(), CategoryFollowOrigin::Person, CarbonImmutable::now());

            return;
        }

        $this->follows->unfollowCategory($userId, $category->id());
    }

    public function autoFollows(Authenticatable $user): bool
    {
        $userId = ForumBrowser::accountId($user);

        return $userId !== null && $this->follows->autoFollows($userId);
    }

    public function setAutoFollows(Authenticatable $user, bool $autoFollows): void
    {
        $userId = ForumBrowser::accountId($user);
        if ($userId !== null) {
            $this->follows->setAutoFollows($userId, $autoFollows, CarbonImmutable::now());
        }
    }

    /**
     * Whether the person muted the discussion. When the mutes cannot be read the discussion is
     * shown as not muted, as the core delivers then.
     */
    private function isMuted(Authenticatable $user, Discussion $discussion): bool
    {
        try {
            return $this->mutes->isMuted($user, ForumMailSubjects::discussion($discussion));
        } catch (Throwable) {
            return false;
        }
    }
}
