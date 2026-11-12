<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use DateTimeImmutable;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * What each person keeps of a discussion when moderation moves its posts: how far they read it
 * and how they follow it. It runs in the transaction of the move, before a merged discussion
 * goes (its rows go with it).
 */
interface DiscussionCarryOverInterface
{
    /**
     * Posts were split off into a new discussion. Whoever follows the original follows the new
     * one the same way; whoever read the original reads the new one as far as the moved posts
     * they had read.
     *
     * @param  array<int, int>  $numbers  new number by the number each moved post had, in order
     */
    public function afterSplit(DiscussionId $from, DiscussionId $to, array $numbers, DateTimeImmutable $at): void;

    /**
     * A discussion was merged into another, its posts appended after `$intoHighest`. Whoever
     * read all of the one it joined reads on as far as they read the merged one (otherwise
     * their place stays: better unread than lost); whoever followed it and chose nothing for
     * the one it joined follows that one the same way.
     *
     * @param  array<int, int>  $numbers  new number by the number each merged post had
     */
    public function afterMerge(DiscussionId $absorbed, DiscussionId $into, array $numbers, int $intoHighest, DateTimeImmutable $at): void;
}
