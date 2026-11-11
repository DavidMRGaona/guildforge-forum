<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

/**
 * How often one person may post and start discussions, as the forum settings say.
 */
interface PostRateLimiterInterface
{
    /**
     * Counts a post by the account and returns null when it is allowed, or the seconds to wait
     * when the account has posted too much lately (then nothing is counted).
     */
    public function attempt(string $accountId): ?int;

    /**
     * The same for starting a discussion, against the discussions-per-hour limit.
     */
    public function attemptDiscussion(string $accountId): ?int;
}
