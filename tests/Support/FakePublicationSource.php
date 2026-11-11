<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use App\Application\Publications\Contracts\PublicationSourceInterface;
use App\Application\Publications\DTOs\PublicationDTO;
use RuntimeException;

/**
 * A publication source whose records the test sets, by type. With failing set, reading throws.
 */
abstract class FakePublicationSource implements PublicationSourceInterface
{
    /** @var array<string, array<string, PublicationDTO>> */
    public static array $records = [];

    /** @var array<string, true> */
    public static array $failing = [];

    /** @var array<string, list<string>>|null ids listed as published, by type; null lists every record */
    public static ?array $listed = null;

    public static function reset(): void
    {
        self::$records = [];
        self::$failing = [];
        self::$listed = null;
    }

    public static function put(PublicationDTO $publication): void
    {
        self::$records[$publication->type][$publication->id] = $publication;
    }

    public static function forget(string $type, string $id): void
    {
        unset(self::$records[$type][$id]);
    }

    public function find(string $id): ?PublicationDTO
    {
        if (isset(self::$failing[$this->type()])) {
            throw new RuntimeException('Publication source unavailable');
        }

        return self::$records[$this->type()][$id] ?? null;
    }

    public function publishedIds(): iterable
    {
        if (isset(self::$failing[$this->type()])) {
            throw new RuntimeException('Publication source unavailable');
        }

        return self::$listed[$this->type()] ?? array_keys(self::$records[$this->type()] ?? []);
    }
}
