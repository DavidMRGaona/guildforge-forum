<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * Where someone stands in a campaign's space (forum#108).
 */
enum SpaceStanding
{
    /** In the campaign's roster: a player or a director */
    case Member;

    /** The roster reads, and they are not in it: never joined, left or were removed */
    case Outside;

    /** The campaign gives no roster any more: taken down, deleted, or its module is gone */
    case Gone;
}
