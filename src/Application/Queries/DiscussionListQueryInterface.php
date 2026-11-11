<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

use Modules\Forum\Domain\ValueObjects\DiscussionTopic;

interface DiscussionListQueryInterface
{
    /**
     * A page of discussions by last activity, newest first. With pinned discussions first,
     * those come apart on the first page and the pages that follow leave them out.
     */
    public function page(DiscussionListCriteria $criteria): DiscussionListPageDTO;

    /**
     * The game-system tags discussions of the categories carry, by label.
     *
     * @param  list<string>  $categoryIds
     * @return list<DiscussionTopic>
     */
    public function topicsIn(array $categoryIds): array;
}
