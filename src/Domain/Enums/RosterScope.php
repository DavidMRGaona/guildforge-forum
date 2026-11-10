<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * Which part of a publication's roster a rule means.
 */
enum RosterScope: string
{
    /** Whoever takes part or leads */
    case Roster = 'roster';

    /** Only whoever leads */
    case Leaders = 'leaders';
}
