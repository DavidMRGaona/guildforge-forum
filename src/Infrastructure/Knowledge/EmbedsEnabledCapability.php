<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Knowledge;

use App\Application\Knowledge\Contracts\KnowledgeCapabilityInterface;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;

/**
 * At least one service whose posts can be embedded is enabled in the forum's settings.
 */
final readonly class EmbedsEnabledCapability implements KnowledgeCapabilityInterface
{
    public function __construct(private ForumSettingsInterface $settings) {}

    public function key(): string
    {
        return 'forum.embeds_enabled';
    }

    public function passes(object $user): bool
    {
        return $this->settings->embedServices() !== [];
    }
}
