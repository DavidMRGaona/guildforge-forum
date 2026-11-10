<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Application\DTOs\CategoryCountersDTO;
use Modules\Forum\Application\DTOs\DiscussionCountersDTO;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * The counters lists show without counting rows. They are recomputed from the posts, never
 * added to or taken from, so a missed write mends itself on the next one. ForumWriter
 * refreshes them inside each write's transaction, after locking the category and then the
 * discussion.
 *
 * - A discussion counts its visible posts; its last post is the visible one with the highest
 *   number, and its last activity is when that post was published (written, or approved; when
 *   the discussion started, with none).
 * - A category counts the discussions that are shown and hold at least one visible post, and
 *   their posts; its last post and activity are those of the most recently active of them.
 *   Subcategories are counted on their own.
 */
interface ForumCountersInterface
{
    public function refreshDiscussion(DiscussionId $id): void;

    public function refreshCategory(CategoryId $id): void;

    public function discussion(DiscussionId $id): ?DiscussionCountersDTO;

    public function category(CategoryId $id): ?CategoryCountersDTO;
}
