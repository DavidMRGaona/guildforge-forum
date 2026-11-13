<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Realtime;

use App\Application\Realtime\Contracts\RealtimeChannelInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Realtime\ForumChannels;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * A discussion's channel: whoever may read the discussion listens, by the same rule as its page
 * (ForumAccessInterface::canRead), so a hidden one is for its moderators and one awaiting
 * approval for its author and moderators. The access service is asked for on each check, not
 * kept: the core keeps this channel for as long as it lives, and the rules are per request.
 */
final readonly class DiscussionRealtimeChannel implements RealtimeChannelInterface
{
    public function __construct(
        private Container $container,
    ) {}

    public function name(): string
    {
        return ForumChannels::DISCUSSION;
    }

    public function authorize(object $user, array $parameters): bool
    {
        $id = $parameters['discussion'] ?? null;
        if (! $user instanceof Authenticatable || ! is_string($id) || ! Str::isUuid($id) || $id !== strtolower($id)) {
            return false;
        }

        $discussion = $this->container->make(DiscussionRepositoryInterface::class)->find(DiscussionId::fromString($id));

        return $discussion instanceof Discussion
            && $this->container->make(ForumAccessInterface::class)->canRead($user, $discussion) === true;
    }
}
