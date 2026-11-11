<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

/**
 * How often one person may post, as the forum settings say.
 */
interface PostRateLimiterInterface
{
    /**
     * Counts a post by the account and returns null when it is allowed, or the seconds to wait
     * when the account has posted too much lately (then nothing is counted).
     */
    public function attempt(string $accountId): ?int;
}
