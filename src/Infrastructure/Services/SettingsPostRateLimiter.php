<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Illuminate\Cache\RateLimiter;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Contracts\PostRateLimiterInterface;

/**
 * Posts per person per minute, from the forum settings, with Laravel's rate limiter.
 */
final readonly class SettingsPostRateLimiter implements PostRateLimiterInterface
{
    private const int WINDOW_SECONDS = 60;

    public function __construct(
        private RateLimiter $limiter,
        private ForumSettingsInterface $settings,
    ) {}

    public function attempt(string $accountId): ?int
    {
        $key = 'forum-posts:'.$accountId;

        if ($this->limiter->tooManyAttempts($key, $this->settings->postsPerMinute())) {
            return $this->limiter->availableIn($key);
        }

        $this->limiter->hit($key, self::WINDOW_SECONDS);

        return null;
    }
}
