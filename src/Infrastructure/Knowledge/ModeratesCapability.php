<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Knowledge;

use App\Application\Knowledge\Contracts\KnowledgeCapabilityInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumBrowser;

/**
 * The person moderates something in the forum: all of it, or at least one category they can read.
 * It reuses the rule the forum's «Moderar» menus ask, so the guide never disagrees with them.
 */
final readonly class ModeratesCapability implements KnowledgeCapabilityInterface
{
    public function __construct(
        private ForumBrowser $browser,
        private CategoryAccessPolicy $policy,
    ) {}

    public function key(): string
    {
        return 'forum.moderates';
    }

    public function passes(object $user): bool
    {
        if (! $user instanceof Authenticatable) {
            return false;
        }

        if ($this->policy->canModerateForum($user)) {
            return true;
        }

        return array_any($this->browser->readableCategories($user), fn ($access) => $access->canModerate);
    }
}
