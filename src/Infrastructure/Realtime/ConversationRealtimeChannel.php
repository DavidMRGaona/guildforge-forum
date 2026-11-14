<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Realtime;

use App\Application\Realtime\Contracts\RealtimeChannelInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Modules\Forum\Application\Conversations\ConversationAccess;
use Modules\Forum\Application\Realtime\ForumChannels;
use Modules\Forum\Application\Services\ForumVisibility;
use Modules\Forum\Domain\ValueObjects\ConversationId;

/**
 * A private conversation's channel (forum#89): only the people in it listen, decided by the one
 * rule every page and endpoint of conversations asks (ConversationAccess::canRead), which fails
 * closed. Whoever leaves stops hearing it once the subscription is checked again. The rule is
 * asked for on each check (see DiscussionRealtimeChannel).
 */
final readonly class ConversationRealtimeChannel implements RealtimeChannelInterface
{
    public function __construct(
        private Container $container,
    ) {}

    public function name(): string
    {
        return ForumChannels::CONVERSATION;
    }

    public function authorize(object $user, array $parameters): bool
    {
        $id = $parameters['conversation'] ?? null;
        if (! $user instanceof Authenticatable || ! is_string($id) || ! Str::isUuid($id) || $id !== strtolower($id)) {
            return false;
        }

        // A forum in preview has no private messages for whoever may not see it
        return $this->container->make(ForumVisibility::class)->isVisibleTo($user)
            && $this->container->make(ConversationAccess::class)->canRead($user, ConversationId::fromString($id)) === true;
    }
}
