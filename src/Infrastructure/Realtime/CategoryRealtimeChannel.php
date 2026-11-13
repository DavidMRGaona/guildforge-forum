<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Realtime;

use App\Application\Realtime\Contracts\RealtimeChannelInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Modules\Forum\Application\Realtime\ForumChannels;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;

/**
 * A category's channel, for the lists that show its discussions: whoever may see the category
 * listens, by the same rule as its page (CategoryAccessPolicy::canView, parent and sources
 * included). The services are asked for on each check (see DiscussionRealtimeChannel).
 */
final readonly class CategoryRealtimeChannel implements RealtimeChannelInterface
{
    public function __construct(
        private Container $container,
    ) {}

    public function name(): string
    {
        return ForumChannels::CATEGORY;
    }

    public function authorize(object $user, array $parameters): bool
    {
        $id = $parameters['category'] ?? null;
        if (! $user instanceof Authenticatable || ! is_string($id) || ! Str::isUuid($id) || $id !== strtolower($id)) {
            return false;
        }

        $category = $this->container->make(CategoryRepositoryInterface::class)->find(CategoryId::fromString($id));

        return $category instanceof Category
            && $this->container->make(CategoryAccessPolicy::class)->canView($user, $category) === true;
    }
}
