<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Publications;

/**
 * What the forum tells in a publication's discussion, as a reply of the system account. Each
 * text is forum::messages.publications.notices.<value>.
 */
enum PublicationNotice: string
{
    case Rescheduled = 'rescheduled';
    case PlaceFreed = 'place_freed';
    case RegistrationClosed = 'registration_closed';
    case Cancelled = 'cancelled';
    case Reactivated = 'reactivated';
    case Withdrawn = 'withdrawn';
    case Deleted = 'deleted';
    case Republished = 'republished';

    /**
     * Whether the notice ends with a link to the publication's page.
     */
    public function linksToPage(): bool
    {
        return match ($this) {
            self::Rescheduled, self::PlaceFreed, self::Reactivated, self::Republished => true,
            default => false,
        };
    }
}
