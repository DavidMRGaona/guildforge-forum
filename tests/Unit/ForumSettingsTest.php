<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit;

use Modules\Forum\Domain\Enums\PostImageType;
use Modules\Forum\Infrastructure\Services\ForumSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ForumSettingsTest extends TestCase
{
    public function test_without_saved_values_it_uses_the_defaults(): void
    {
        config(['modules.settings.forum' => []]);

        $this->assertSame(ForumSettings::DEFAULT_PAGE_SIZE, (new ForumSettings)->discussionsPerPage());
        $this->assertSame(ForumSettings::DEFAULT_PAGE_SIZE, (new ForumSettings)->postsPerPage());
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function storedValues(): iterable
    {
        yield 'within bounds' => [30, 30];
        yield 'numeric string' => ['25', 25];
        yield 'below the minimum' => [2, ForumSettings::MIN_PAGE_SIZE];
        yield 'above the maximum' => [500, ForumSettings::MAX_PAGE_SIZE];
        yield 'not a number' => ['muchos', ForumSettings::DEFAULT_PAGE_SIZE];
    }

    #[DataProvider('storedValues')]
    public function test_stored_page_sizes_are_kept_within_bounds(mixed $stored, int $expected): void
    {
        config(['modules.settings.forum' => ['discussions_per_page' => $stored, 'posts_per_page' => $stored]]);

        $this->assertSame($expected, (new ForumSettings)->discussionsPerPage());
        $this->assertSame($expected, (new ForumSettings)->postsPerPage());
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function storedPostRates(): iterable
    {
        yield 'unset' => [null, ForumSettings::DEFAULT_POSTS_PER_MINUTE];
        yield 'within bounds' => [10, 10];
        yield 'below the minimum' => [0, ForumSettings::MIN_POSTS_PER_MINUTE];
        yield 'above the maximum' => [1000, ForumSettings::MAX_POSTS_PER_MINUTE];
        yield 'not a number' => ['muchos', ForumSettings::DEFAULT_POSTS_PER_MINUTE];
    }

    #[DataProvider('storedPostRates')]
    public function test_the_posts_per_minute_are_kept_within_bounds(mixed $stored, int $expected): void
    {
        config(['modules.settings.forum' => ['posts_per_minute' => $stored]]);

        $this->assertSame($expected, (new ForumSettings)->postsPerMinute());
    }

    public function test_the_image_formats_ignore_anything_that_is_not_an_allowed_format(): void
    {
        config(['modules.settings.forum' => []]);
        $this->assertSame(PostImageType::cases(), (new ForumSettings)->imageTypes());

        config(['modules.settings.forum' => ['image_types' => ['png', 'svg', 'gif', 'bmp']]]);
        $this->assertSame([PostImageType::Png, PostImageType::Gif], (new ForumSettings)->imageTypes());

        config(['modules.settings.forum' => ['image_types' => []]]);
        $this->assertSame([], (new ForumSettings)->imageTypes());
    }

    public function test_the_image_limits_are_kept_within_bounds(): void
    {
        config(['modules.settings.forum' => []]);
        $settings = new ForumSettings;
        $this->assertSame([4096, 2000, 10, 30], [$settings->imageMaxKilobytes(), $settings->imageMaxDimension(), $settings->imagesPerPost(), $settings->imageUploadsPerHour()]);

        config(['modules.settings.forum' => ['image_max_kilobytes' => 1, 'image_max_dimension' => 99_999, 'images_per_post' => 0, 'image_uploads_per_hour' => 'muchas']]);
        $settings = new ForumSettings;
        $this->assertSame(
            [ForumSettings::MIN_IMAGE_MAX_KILOBYTES, ForumSettings::MAX_IMAGE_MAX_DIMENSION, ForumSettings::MIN_IMAGES_PER_POST, ForumSettings::DEFAULT_IMAGE_UPLOADS_PER_HOUR],
            [$settings->imageMaxKilobytes(), $settings->imageMaxDimension(), $settings->imagesPerPost(), $settings->imageUploadsPerHour()],
        );
    }
}
