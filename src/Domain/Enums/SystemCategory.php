<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * The categories the forum relies on, with keys that never change: automatic discussions about
 * game tables, campaigns and events go into them. They may be renamed and restyled, never deleted.
 */
enum SystemCategory: string
{
    case GameTables = 'game-tables';
    case Campaigns = 'campaigns';
    case Events = 'events';
}
