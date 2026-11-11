<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\SubjectDiscussion;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PublicationKey;

interface SubjectDiscussionRepositoryInterface
{
    /**
     * The publication's record with its row locked until the surrounding transaction ends,
     * created empty first when there is none, so two checks of the same publication run one
     * after the other.
     */
    public function lockOrCreate(PublicationKey $key): SubjectDiscussion;

    public function find(PublicationKey $key): ?SubjectDiscussion;

    public function findByDiscussion(DiscussionId $discussionId): ?SubjectDiscussion;

    public function save(SubjectDiscussion $record): void;

    /**
     * Ids of the publications of a type the forum still follows (published and not over when
     * last read), for the daily reconciliation.
     *
     * @return iterable<string>
     */
    public function liveIds(string $type): iterable;
}
