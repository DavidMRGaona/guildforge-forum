<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Search;

use App\Application\Search\Services\SearchIndexerInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Forum\Infrastructure\Jobs\RefreshForumSearchJob;

/**
 * Tells the site search what changed. Each call is queued for when the current transaction commits and
 * the indexer then reads the tables as they are, so the order of the writes does not matter.
 */
final readonly class ForumSearchRefresher
{
    public function __construct(
        private SearchIndexerInterface $indexer,
        private Dispatcher $bus,
    ) {}

    public function discussion(string $id): void
    {
        $this->indexer->refresh(DiscussionSearchSource::TYPE, $id);
    }

    public function post(string $id): void
    {
        $this->indexer->refresh(PostSearchSource::TYPE, $id);
    }

    public function forgetDiscussion(string $id): void
    {
        $this->indexer->forget(DiscussionSearchSource::TYPE, $id);
    }

    public function forgetPost(string $id): void
    {
        $this->indexer->forget(PostSearchSource::TYPE, $id);
    }

    /**
     * Every reply of the discussion (its title, address or visibility changed).
     */
    public function repliesOf(string $discussionId): void
    {
        $this->bus->dispatch((new RefreshForumSearchJob(RefreshForumSearchJob::DISCUSSION, $discussionId))->afterCommit());
    }

    /**
     * Everything in the category and its subcategories (who reads it, its name or its place changed).
     */
    public function category(string $categoryId): void
    {
        $this->bus->dispatch((new RefreshForumSearchJob(RefreshForumSearchJob::CATEGORY, $categoryId))->afterCommit());
    }
}
