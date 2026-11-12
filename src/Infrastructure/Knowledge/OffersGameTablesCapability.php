<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Knowledge;

use Modules\Forum\Domain\Enums\SystemCategory;

/**
 * Game tables are offered: each published table has its topic in «Mesas».
 */
final readonly class OffersGameTablesCapability extends OffersPublicationCapability
{
    public function key(): string
    {
        return 'forum.offers_game_tables';
    }

    protected function publicationType(): string
    {
        return SystemCategory::GameTables->publicationType();
    }
}
