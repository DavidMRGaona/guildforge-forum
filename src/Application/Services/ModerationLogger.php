<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\ModerationLogEntry;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Repositories\ModerationLogRepositoryInterface;

/**
 * Writes what moderation did to the moderation log, in the transaction of the change itself, so
 * a change never goes without its entry nor an entry without its change. Every entry names the
 * discussion by its title and number, which outlive the discussion in the log.
 */
final readonly class ModerationLogger
{
    public function __construct(
        private ModerationLogRepositoryInterface $log,
    ) {}

    /**
     * @param  array<string, string|int|bool|null>  $details
     */
    public function record(ModerationAction $action, Authenticatable $moderator, Discussion $discussion, ?Post $post = null, ?string $reason = null, array $details = []): void
    {
        $this->log->record(new ModerationLogEntry(
            id: (string) Str::uuid7(),
            action: $action,
            moderatorId: ForumBrowser::accountId($moderator),
            subjectUserId: $post instanceof Post ? $post->authorId() : $discussion->authorId(),
            categoryId: $discussion->categoryId()->value,
            discussionId: $discussion->id()->value,
            postId: $post?->id()->value,
            reason: $reason,
            details: [
                'discussion_title' => $discussion->title()->value,
                'discussion_number' => $discussion->number(),
                'post_number' => $post?->number()?->value,
                ...$details,
            ],
            at: CarbonImmutable::now(),
        ));
    }

    /**
     * What moderation did to a person rather than to a discussion (a suspension).
     *
     * @param  array<string, string|int|bool|null>  $details
     */
    public function recordForAccount(ModerationAction $action, Authenticatable $moderator, string $subjectUserId, ?string $reason = null, array $details = []): void
    {
        $this->log->record(new ModerationLogEntry(
            id: (string) Str::uuid7(),
            action: $action,
            moderatorId: ForumBrowser::accountId($moderator),
            subjectUserId: $subjectUserId,
            categoryId: null,
            discussionId: null,
            postId: null,
            reason: $reason,
            details: $details,
            at: CarbonImmutable::now(),
        ));
    }
}
