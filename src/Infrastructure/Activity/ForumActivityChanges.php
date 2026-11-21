<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Activity;

use App\Application\Activity\Services\ActivityPublisherInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Modules\Forum\Infrastructure\Jobs\PublishForumActivityJob;
use Throwable;

/**
 * Tells the activity contract what may have stopped or started counting (forum#151). One post or
 * discussion is published at once (the core announces it when the transaction commits, once per
 * subject); what depends on a discussion, a category or the whole forum goes to a queued job,
 * after the commit, so a big category never slows down the write. A queue that cannot take the job
 * is logged and never makes the write fail.
 */
final readonly class ForumActivityChanges
{
    public function __construct(
        private ActivityPublisherInterface $activity,
        private Dispatcher $bus,
    ) {}

    public function post(string $id): void
    {
        $this->activity->recorded(PostActivitySource::TYPE, $id);
    }

    public function discussion(string $id): void
    {
        $this->activity->recorded(DiscussionActivitySource::TYPE, $id);
    }

    /**
     * Every post of the discussion (it was hidden, shown, moved or approved).
     */
    public function postsOf(string $discussionId): void
    {
        $this->queue(new PublishForumActivityJob(PublishForumActivityJob::DISCUSSION, $discussionId));
    }

    /**
     * Everything in the category and its subcategories (who reads it, or where it hangs, changed).
     */
    public function category(string $categoryId): void
    {
        $this->queue(new PublishForumActivityJob(PublishForumActivityJob::CATEGORY, $categoryId));
    }

    /**
     * Everything in the forum (it was opened, or turned back into a preview).
     */
    public function everything(): void
    {
        $this->queue(new PublishForumActivityJob(PublishForumActivityJob::EVERYTHING));
    }

    private function queue(PublishForumActivityJob $job): void
    {
        try {
            $this->bus->dispatch($job->afterCommit());
        } catch (Throwable $exception) {
            Log::warning('[Forum] Could not queue publishing the forum activity again.', ['scope' => $job->scope, 'exception' => $exception::class]);
        }
    }
}
