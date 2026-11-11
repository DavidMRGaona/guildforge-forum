<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * How moderation settled a report. The values are stored.
 */
enum ReportResolution: string
{
    /** The post was hidden */
    case Hidden = 'hidden';

    /** Nothing needed doing */
    case NoAction = 'no_action';

    /** The post was edited */
    case Edited = 'edited';
}
