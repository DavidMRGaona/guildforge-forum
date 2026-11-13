<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Realtime;

use App\Application\Realtime\Contracts\RealtimeChannelInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Realtime\ForumChannels;
use Modules\Forum\Application\Services\ForumBrowser;

/**
 * A person's own channel, for the forum's mark in the menu: only that account listens. What it
 * hears carries nothing; the menu asks for its count through its usual request.
 */
final readonly class AccountRealtimeChannel implements RealtimeChannelInterface
{
    public function name(): string
    {
        return ForumChannels::ACCOUNT;
    }

    public function authorize(object $user, array $parameters): bool
    {
        $accountId = $user instanceof Authenticatable ? ForumBrowser::accountId($user) : null;

        return $accountId !== null && ($parameters['account'] ?? null) === $accountId;
    }
}
