<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * Why a person follows a category.
 */
enum CategoryFollowOrigin: string
{
    /** They chose it */
    case Person = 'person';

    /** They joined the roster of the campaign the category is the space of; leaving it ends the follow */
    case Roster = 'roster';
}
