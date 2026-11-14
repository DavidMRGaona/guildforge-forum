<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Knowledge;

use App\Application\Knowledge\Contracts\KnowledgeCapabilityInterface;
use Modules\Forum\Application\Services\ForumVisibility;

/**
 * The reader sees the forum: open to the whole site, or a preview they may see. Every guide of the
 * site about the forum asks it, so a preview shows none of them to anyone else.
 */
final readonly class ForumVisibleCapability implements KnowledgeCapabilityInterface
{
    public function __construct(private ForumVisibility $visibility) {}

    public function key(): string
    {
        return 'forum.visible';
    }

    public function passes(object $user): bool
    {
        return $this->visibility->isVisibleTo($user);
    }
}
