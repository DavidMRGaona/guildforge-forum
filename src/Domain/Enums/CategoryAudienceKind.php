<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * Who a category rule lets in.
 */
enum CategoryAudienceKind: string
{
    /** Anyone, without an account (only for reading) */
    case Everyone = 'everyone';

    /** Any signed-in account */
    case SignedIn = 'signed_in';

    /** Members of the association (the role chosen in the site settings) */
    case AssociationMembers = 'association_members';

    /** Holders of the chosen roles, while those roles are active */
    case Roles = 'roles';

    /** Whoever takes part in or leads the category's publication (a campaign's players and directors) */
    case Roster = 'roster';

    /** Whoever leads the category's publication (a campaign's directors) */
    case RosterLeaders = 'roster_leaders';

    /**
     * How far the audience reaches: everyone above accounts, accounts above any group of them.
     */
    public function reach(): int
    {
        return match ($this) {
            self::Everyone => 3,
            self::SignedIn => 2,
            default => 1,
        };
    }

    public function needsSubject(): bool
    {
        return $this === self::Roster || $this === self::RosterLeaders;
    }
}
