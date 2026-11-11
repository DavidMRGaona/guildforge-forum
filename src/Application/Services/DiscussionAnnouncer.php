<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Domain\Forum\Events\ForumDiscussionStarted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Throwable;

/**
 * Tells the site's channel modules about a discussion someone started, through the core event
 * ForumDiscussionStarted, once it is saved (or, if it awaited approval, once approved). It fails
 * closed: only a discussion that is shown and approved,
 * in a category guests can read and the settings chose, and that no publication opened (the
 * categories of tables, campaigns and events and the publication spaces announce through
 * their publication). Nothing personal goes out: no author.
 */
final readonly class DiscussionAnnouncer
{
    public const int EXCERPT_LENGTH = 300;

    public function __construct(
        private CategoryAccessPolicy $policy,
        private ForumSettingsInterface $settings,
        private ForumUrlsInterface $urls,
    ) {}

    /**
     * @param  list<DiscussionTopic>  $topics
     */
    public function announce(Discussion $discussion, Category $category, PostBody $firstPost, array $topics): void
    {
        if (! $this->isDue($discussion, $category)) {
            return;
        }

        try {
            $event = new ForumDiscussionStarted(
                discussionId: $discussion->id()->value,
                title: $discussion->title()->value,
                url: $this->urls->discussion($discussion, absolute: true),
                excerpt: Str::limit(trim($firstPost->plainText), self::EXCERPT_LENGTH),
                tags: [$category->name(), ...array_map(static fn (DiscussionTopic $topic): string => $topic->label, $topics)],
            );
        } catch (Throwable $exception) {
            // An announcement that cannot be built (no public URL) is skipped, never half sent
            report($exception);

            return;
        }

        // After the commit, so a discussion that was rolled back is never announced
        DB::afterCommit(static function () use ($event): void {
            event($event);
        });
    }

    private function isDue(Discussion $discussion, Category $category): bool
    {
        return ! $discussion->isHidden()
            && ! $discussion->isAwaitingApproval()
            && ! $category->isSystem()
            && ! $category->isPublicationSpace()
            && in_array($category->id()->value, $this->settings->announcedCategoryIds(), true)
            && $this->policy->canView(null, $category);
    }
}
