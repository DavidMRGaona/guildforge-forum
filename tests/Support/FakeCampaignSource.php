<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use App\Application\Publications\Contracts\PublicationRosterInterface;
use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\DTOs\PublicationRosterDTO;
use RuntimeException;

/**
 * Campaigns with rosters, as a campaign module offers them. A roster is only given for a record
 * find() returns, as the contract says; with $rosterFails set, rosters cannot be read.
 */
final class FakeCampaignSource extends FakePublicationSource implements PublicationRosterInterface
{
    public const string TYPE = 'campaign';

    /** @var array<string, PublicationRosterDTO> */
    public static array $rosters = [];

    public static bool $rosterFails = false;

    /** How many times a roster was asked for */
    public static int $rosterReads = 0;

    public static function rosterReads(): int
    {
        return self::$rosterReads;
    }

    public static function resetRosters(): void
    {
        self::$rosters = [];
        self::$rosterFails = false;
        self::$rosterReads = 0;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function roster(string $id): ?PublicationRosterDTO
    {
        self::$rosterReads++;
        if (self::$rosterFails) {
            throw new RuntimeException('Roster unavailable');
        }

        return $this->find($id) instanceof PublicationDTO ? self::$rosters[$id] ?? new PublicationRosterDTO : (null);
    }

    public function rostersOf(string $userId): array
    {
        return [];
    }
}
