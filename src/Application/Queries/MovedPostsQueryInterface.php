<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * The gaps moderation left in a discussion by moving its posts away.
 */
interface MovedPostsQueryInterface
{
    /**
     * The numbers in the range (to the end when `$through` is null) whose posts left the
     * discussion, in order, with where each post is now.
     *
     * @return list<MovedPostRowDTO>
     */
    public function inRange(DiscussionId $from, int $fromNumber, ?int $through): array;

    /**
     * Where to resume each discussion from a number, when that number moved away: the first
     * numbered post after it. Discussions whose number did not move are left out (resume there),
     * and so are those with no post after it.
     *
     * @param  array<string, int>  $numbers  by discussion id
     * @return array<string, int> by discussion id
     */
    public function resumeAfterMoves(array $numbers): array;
}
