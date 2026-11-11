<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * Who made a category read-only. The forum only lifts what it set itself.
 */
enum ReadOnlyOrigin: string
{
    /** The forum, because of its publication (a campaign over, cancelled or taken down) */
    case System = 'system';

    /** Someone who manages the forum, by hand */
    case Moderation = 'moderation';
}
