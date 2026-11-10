<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use App\Application\Publications\Contracts\PublicationRosterInterface;
use App\Application\Publications\Contracts\PublicationSourceInterface;
use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\DTOs\PublicationRosterDTO;
use RuntimeException;

/**
 * A publication source with rosters, as a campaign module would offer. Its rosters are set by
 * the test; with $fails set, every roster lookup throws.
 */
final class ExampleCampaignSource implements PublicationRosterInterface, PublicationSourceInterface
{
    public const string TYPE = 'example-campaign';

    /** @var array<string, PublicationRosterDTO> */
    public static array $rosters = [];

    public static bool $fails = false;

    public static int $lookups = 0;

    public static function reset(): void
    {
        self::$rosters = [];
        self::$fails = false;
        self::$lookups = 0;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function find(string $id): ?PublicationDTO
    {
        return null;
    }

    public function publishedIds(): iterable
    {
        return array_keys(self::$rosters);
    }

    public function roster(string $id): ?PublicationRosterDTO
    {
        self::$lookups++;

        if (self::$fails) {
            throw new RuntimeException('Roster unavailable');
        }

        return self::$rosters[$id] ?? null;
    }

    public function rostersOf(string $userId): array
    {
        return [];
    }
}
