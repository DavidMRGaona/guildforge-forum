<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Domain\Enums\CharacterAccessMode;
use Modules\Forum\Domain\Enums\PostImageType;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * The module settings: release defaults (config/settings.php) with what the panel saved on top.
 * A stored value out of bounds is brought back within them, and one that is not a number gives
 * the default.
 */
final readonly class ForumSettings implements ForumSettingsInterface
{
    public const int DEFAULT_PAGE_SIZE = 20;

    public const int MIN_PAGE_SIZE = 10;

    public const int MAX_PAGE_SIZE = 100;

    public const int DEFAULT_POSTS_PER_MINUTE = 6;

    public const int MIN_POSTS_PER_MINUTE = 1;

    public const int MAX_POSTS_PER_MINUTE = 60;

    public const int DEFAULT_DISCUSSIONS_PER_HOUR = 5;

    public const int MIN_DISCUSSIONS_PER_HOUR = 1;

    public const int MAX_DISCUSSIONS_PER_HOUR = 60;

    public const int DEFAULT_IMAGE_MAX_KILOBYTES = 4096;

    public const int MIN_IMAGE_MAX_KILOBYTES = 128;

    public const int MAX_IMAGE_MAX_KILOBYTES = 10_240;

    public const int DEFAULT_IMAGE_MAX_DIMENSION = 2000;

    public const int MIN_IMAGE_MAX_DIMENSION = 320;

    public const int MAX_IMAGE_MAX_DIMENSION = 4000;

    public const int DEFAULT_IMAGES_PER_POST = 10;

    public const int MIN_IMAGES_PER_POST = 1;

    public const int MAX_IMAGES_PER_POST = 20;

    public const int DEFAULT_IMAGE_UPLOADS_PER_HOUR = 30;

    public const int MIN_IMAGE_UPLOADS_PER_HOUR = 1;

    public const int MAX_IMAGE_UPLOADS_PER_HOUR = 200;

    public const array DEFAULT_EXTERNAL_IMAGE_HOSTS = ['imgur.com'];

    public const int MAX_EXTERNAL_IMAGE_HOSTS = 30;

    public function discussionsPerPage(): int
    {
        return $this->pageSize('discussions_per_page');
    }

    public function postsPerPage(): int
    {
        return $this->pageSize('posts_per_page');
    }

    public function postsPerMinute(): int
    {
        return $this->bounded('posts_per_minute', self::MIN_POSTS_PER_MINUTE, self::MAX_POSTS_PER_MINUTE, self::DEFAULT_POSTS_PER_MINUTE);
    }

    public function discussionsPerHour(): int
    {
        return $this->bounded('discussions_per_hour', self::MIN_DISCUSSIONS_PER_HOUR, self::MAX_DISCUSSIONS_PER_HOUR, self::DEFAULT_DISCUSSIONS_PER_HOUR);
    }

    public function imageTypes(): array
    {
        $stored = config('modules.settings.forum.image_types');
        if (! is_array($stored)) {
            return PostImageType::cases();
        }

        // Unknown values (an SVG slipped into the settings) are ignored, never allowed
        return array_values(array_filter(
            PostImageType::cases(),
            static fn (PostImageType $type): bool => in_array($type->value, $stored, true),
        ));
    }

    public function imageMaxKilobytes(): int
    {
        return $this->bounded('image_max_kilobytes', self::MIN_IMAGE_MAX_KILOBYTES, self::MAX_IMAGE_MAX_KILOBYTES, self::DEFAULT_IMAGE_MAX_KILOBYTES);
    }

    public function imageMaxDimension(): int
    {
        return $this->bounded('image_max_dimension', self::MIN_IMAGE_MAX_DIMENSION, self::MAX_IMAGE_MAX_DIMENSION, self::DEFAULT_IMAGE_MAX_DIMENSION);
    }

    public function imagesPerPost(): int
    {
        return $this->bounded('images_per_post', self::MIN_IMAGES_PER_POST, self::MAX_IMAGES_PER_POST, self::DEFAULT_IMAGES_PER_POST);
    }

    public function imageUploadsPerHour(): int
    {
        return $this->bounded('image_uploads_per_hour', self::MIN_IMAGE_UPLOADS_PER_HOUR, self::MAX_IMAGE_UPLOADS_PER_HOUR, self::DEFAULT_IMAGE_UPLOADS_PER_HOUR);
    }

    public function announcedCategoryIds(): array
    {
        $stored = config('modules.settings.forum.announced_categories');
        if (! is_array($stored)) {
            return [];
        }

        // Only well-formed ids: anything else in the settings never matches a category
        return array_values(array_unique(array_map(
            strtolower(...),
            array_filter($stored, static fn (mixed $id): bool => is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1),
        )));
    }

    public function characterAccess(): CharacterAccessMode
    {
        $stored = config('modules.settings.forum.characters_access');

        // An unknown value turns characters off rather than opening them to everyone
        return is_string($stored) ? (CharacterAccessMode::tryFrom($stored) ?? CharacterAccessMode::Nobody) : CharacterAccessMode::Everyone;
    }

    public function externalImageHosts(): array
    {
        $stored = config('modules.settings.forum.external_image_hosts');
        if (! is_array($stored)) {
            return self::DEFAULT_EXTERNAL_IMAGE_HOSTS;
        }

        // Only host names: a scheme, a wildcard or an address in the settings never matches
        $hosts = array_filter(
            array_map(static fn (mixed $host): mixed => is_string($host) ? strtolower(trim($host)) : null, $stored),
            static fn (mixed $host): bool => is_string($host) && RemoteUrl::isHostName($host),
        );

        return array_slice(array_values(array_unique($hosts)), 0, self::MAX_EXTERNAL_IMAGE_HOSTS);
    }

    private function pageSize(string $key): int
    {
        return $this->bounded($key, self::MIN_PAGE_SIZE, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE);
    }

    private function bounded(string $key, int $min, int $max, int $default): int
    {
        $value = config("modules.settings.forum.{$key}");

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}
