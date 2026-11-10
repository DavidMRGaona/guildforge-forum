<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * Whether a discussion takes replies. Being pinned or hidden is separate: a pinned discussion
 * may be closed (a rules thread), and a hidden one keeps whether it was open.
 */
enum DiscussionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
