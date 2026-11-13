<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Realtime;

use App\Application\Realtime\Services\RealtimeConfigurationInterface;
use App\Application\Realtime\Services\RealtimePublisherInterface;
use Illuminate\Support\Facades\Log;
use Modules\Forum\Application\Contracts\ConversationParticipantsInterface;
use Modules\Forum\Application\Contracts\ForumLiveSignalsInterface;
use Modules\Forum\Application\Realtime\ForumChannels;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostNumber;
use Modules\Forum\Infrastructure\Jobs\SignalFollowersJob;
use Throwable;

/**
 * The forum's real-time messages through the core's publisher, which sends them once the write
 * commits. Whoever follows a discussion or its category hears about a new post from a queued job,
 * so the write never waits for the list of followers. Without real time nothing is sent nor
 * queued; a failure only means pages catch up through their own requests, so it is logged.
 */
final readonly class RealtimeForumSignals implements ForumLiveSignalsInterface
{
    public function __construct(
        private RealtimePublisherInterface $publisher,
        private RealtimeConfigurationInterface $configuration,
        private DiscussionRepositoryInterface $discussions,
        private ConversationParticipantsInterface $participants,
    ) {}

    public function postPublished(Post $post): void
    {
        $this->quietly(function () use ($post): void {
            $number = $post->number();
            if (! $number instanceof PostNumber || ! $post->isVisible()) {
                return;
            }

            $discussionId = $post->discussionId()->value;
            $this->publisher->publish(ForumChannels::discussion($discussionId), ForumChannels::POST_CREATED, ['number' => $number->value]);

            // A category's readers may not all see a hidden discussion or one awaiting approval
            $discussion = $this->discussions->find($post->discussionId());
            if ($discussion instanceof Discussion && ! $discussion->isHidden() && ! $discussion->isAwaitingApproval()) {
                $this->publisher->publish(ForumChannels::category($discussion->categoryId()->value), ForumChannels::DISCUSSION_ACTIVITY, [
                    'discussion' => $discussionId,
                    'number' => $number->value,
                ]);
            }

            SignalFollowersJob::dispatch($post->id()->value)->afterCommit();
        });
    }

    public function postChanged(Post $post): void
    {
        $this->quietly(function () use ($post): void {
            $number = $post->number();
            if ($number instanceof PostNumber) {
                $this->publisher->publish(ForumChannels::discussion($post->discussionId()->value), ForumChannels::POST_CHANGED, ['number' => $number->value]);
            }
        });
    }

    public function discussionChanged(DiscussionId $discussionId): void
    {
        $this->quietly(fn () => $this->publisher->publish(ForumChannels::discussion($discussionId->value), ForumChannels::DISCUSSION_CHANGED));
    }

    public function conversationEntryAdded(ConversationId $conversationId, int $number, ?string $authorId): void
    {
        $this->quietly(function () use ($conversationId, $number, $authorId): void {
            $this->publisher->publish(ForumChannels::conversation($conversationId->value), ForumChannels::ENTRY_CREATED, ['number' => $number]);

            // At most ten people: each menu asks for its own count
            foreach ($this->participants->of($conversationId) as $accountId) {
                if ($accountId !== $authorId) {
                    $this->publisher->publish(ForumChannels::account($accountId), ForumChannels::PULSE_CHANGED);
                }
            }
        });
    }

    public function conversationEntryChanged(ConversationId $conversationId, int $number): void
    {
        $this->quietly(fn () => $this->publisher->publish(ForumChannels::conversation($conversationId->value), ForumChannels::ENTRY_CHANGED, ['number' => $number]));
    }

    public function pulseChanged(string $accountId): void
    {
        $this->quietly(fn () => $this->publisher->publish(ForumChannels::account($accountId), ForumChannels::PULSE_CHANGED));
    }

    /**
     * @param  callable(): void  $signal
     */
    private function quietly(callable $signal): void
    {
        try {
            if ($this->configuration->enabled()) {
                $signal();
            }
        } catch (Throwable $exception) {
            Log::warning('The forum could not send a real-time message; open pages catch up by themselves.', ['exception' => $exception->getMessage()]);
        }
    }
}
