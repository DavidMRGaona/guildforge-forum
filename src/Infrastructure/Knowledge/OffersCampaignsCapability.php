<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Knowledge;

use Modules\Forum\Domain\Enums\SystemCategory;

/**
 * Campaigns are offered: each published campaign has its topic in «Campañas» and its own space.
 */
final readonly class OffersCampaignsCapability extends OffersPublicationCapability
{
    public function key(): string
    {
        return 'forum.offers_campaigns';
    }

    protected function publicationType(): string
    {
        return SystemCategory::Campaigns->publicationType();
    }
}
