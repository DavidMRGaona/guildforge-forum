<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Illuminate\Cache\RateLimiter;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Contracts\PostRateLimiterInterface;

/**
 * Posts per person per minute and discussions per person per hour, from the forum settings,
 * with Laravel's rate limiter.
 */
final readonly class SettingsPostRateLimiter implements PostRateLimiterInterface
{
    private const int WINDOW_SECONDS = 60;

    private const int DISCUSSION_WINDOW_SECONDS = 3600;

    public function __construct(
        private RateLimiter $limiter,
        private ForumSettingsInterface $settings,
    ) {}

    public function attempt(string $accountId): ?int
    {
        return $this->hit('forum-posts:'.$accountId, $this->settings->postsPerMinute(), self::WINDOW_SECONDS);
    }

    public function attemptDiscussion(string $accountId): ?int
    {
        return $this->hit('forum-discussions:'.$accountId, $this->settings->discussionsPerHour(), self::DISCUSSION_WINDOW_SECONDS);
    }

    private function hit(string $key, int $max, int $window): ?int
    {
        if ($this->limiter->tooManyAttempts($key, $max)) {
            return max(1, $this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, $window);

        return null;
    }
}
