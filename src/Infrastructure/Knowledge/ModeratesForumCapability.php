<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Knowledge;

use App\Application\Knowledge\Contracts\KnowledgeCapabilityInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Services\CategoryAccessPolicy;

/**
 * The person moderates the whole forum (and is not suspended): the same rule that opens the
 * forum's moderation page in the panel.
 */
final readonly class ModeratesForumCapability implements KnowledgeCapabilityInterface
{
    public function __construct(private CategoryAccessPolicy $policy) {}

    public function key(): string
    {
        return 'forum.moderates_forum';
    }

    public function passes(object $user): bool
    {
        return $user instanceof Authenticatable && $this->policy->canModerateForum($user);
    }
}
