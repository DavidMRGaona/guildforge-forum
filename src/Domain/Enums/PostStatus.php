<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

enum PostStatus: string
{
    /** Readable by whoever reads the discussion */
    case Visible = 'visible';

    /** Hidden by a moderator: readers see a placeholder with its number */
    case Hidden = 'hidden';

    /** Waiting for a moderator's approval: no number until it is approved */
    case Pending = 'pending';

    /** Deleted by its author: readers see a placeholder with its number */
    case Deleted = 'deleted';

    /**
     * Whether the post holds a place in the numbering (every status but pending).
     */
    public function isNumbered(): bool
    {
        return $this !== self::Pending;
    }
}
