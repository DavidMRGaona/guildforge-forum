<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

/**
 * Why a publication's discussion takes no replies, as its page tells it.
 */
enum SubjectClosedReason: string
{
    /** The publication was cancelled, and the forum closed its discussion */
    case Cancelled = 'cancelled';

    /** The publication was taken down or deleted, and the forum closed its discussion */
    case Withdrawn = 'withdrawn';

    /** A moderator closed it */
    case Moderation = 'moderation';

    /** The forum closed it, for a reason it no longer knows */
    case Closed = 'closed';
}
