<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Embeds\EmbedProviders;

/**
 * The origins of the players of the enabled services, for the core's frame-src. Read from the
 * settings each time, so a service turned off leaves the policy on the next request.
 */
final readonly class EmbedFrameOrigins
{
    public function __construct(
        private EmbedProviders $providers,
        private ForumSettingsInterface $settings,
    ) {}

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        $origins = [];
        foreach ($this->settings->embedServices() as $key) {
            foreach ($this->providers->get($key)?->frameOrigins() ?? [] as $origin) {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }
}
