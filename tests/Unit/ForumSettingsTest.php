<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit;

use Modules\Forum\Domain\Enums\PostImageType;
use Modules\Forum\Domain\Enums\SubjectConversationMode;
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

    public function test_announced_categories_keep_only_well_formed_ids(): void
    {
        config(['modules.settings.forum' => []]);
        $this->assertSame([], (new ForumSettings)->announcedCategoryIds());

        $id = '0D2F3C4B-1A2B-4C3D-8E9F-0A1B2C3D4E5F';
        config(['modules.settings.forum' => ['announced_categories' => [$id, 'general', 42, ['x'], $id]]]);
        $this->assertSame([strtolower($id)], (new ForumSettings)->announcedCategoryIds());

        config(['modules.settings.forum' => ['announced_categories' => 'todas']]);
        $this->assertSame([], (new ForumSettings)->announcedCategoryIds());
    }

    public function test_images_from_other_sites_come_from_imgur_by_default(): void
    {
        config(['modules.settings.forum' => []]);

        $this->assertSame(['imgur.com'], (new ForumSettings)->externalImageHosts());
    }

    public function test_the_image_sites_keep_only_host_names(): void
    {
        config(['modules.settings.forum' => ['external_image_hosts' => [' Imgur.com ', 'https://postimg.cc', '*.ibb.co', '127.0.0.1', 'localhost', 'i.ibb.co', 42, 'imgur.com']]]);
        $this->assertSame(['imgur.com', 'i.ibb.co'], (new ForumSettings)->externalImageHosts());

        // An empty list turns them off; anything that is not a list gives the default
        config(['modules.settings.forum' => ['external_image_hosts' => []]]);
        $this->assertSame([], (new ForumSettings)->externalImageHosts());
        config(['modules.settings.forum' => ['external_image_hosts' => 'imgur.com']]);
        $this->assertSame(['imgur.com'], (new ForumSettings)->externalImageHosts());
    }

    public function test_every_service_is_embedded_by_default_and_unknown_keys_are_ignored(): void
    {
        config(['modules.settings.forum' => []]);
        $this->assertSame(['reddit', 'x', 'bluesky', 'instagram', 'tiktok', 'youtube', 'vimeo', 'twitch'], (new ForumSettings)->embedServices());

        config(['modules.settings.forum' => ['embed_services' => ['youtube', 'myspace', 'reddit']]]);
        $this->assertSame(['reddit', 'youtube'], (new ForumSettings)->embedServices());

        config(['modules.settings.forum' => ['embed_services' => []]]);
        $this->assertSame([], (new ForumSettings)->embedServices());
    }

    public function test_cookie_categories_are_slugs_or_marketing(): void
    {
        config(['modules.settings.forum' => ['embed_cookie_categories' => ['youtube' => 'videos', 'x' => 'Mal escrito', 'vimeo' => 42]]]);
        $settings = new ForumSettings;

        $this->assertSame('videos', $settings->embedCookieCategory('youtube'));
        $this->assertSame('marketing', $settings->embedCookieCategory('x'));
        $this->assertSame('marketing', $settings->embedCookieCategory('vimeo'));
        $this->assertSame('marketing', $settings->embedCookieCategory('reddit'));
    }

    public function test_record_pages_show_no_conversation_until_the_site_chooses(): void
    {
        config(['modules.settings.forum' => []]);

        $this->assertSame(SubjectConversationMode::Hidden, (new ForumSettings)->subjectConversationMode('event'));
    }

    public function test_each_record_type_has_its_own_conversation_mode(): void
    {
        config(['modules.settings.forum' => ['subject_conversations' => ['event' => 'read', 'game-table' => 'reply', 'campaign' => 'hidden']]]);
        $settings = new ForumSettings;

        $this->assertSame(SubjectConversationMode::Read, $settings->subjectConversationMode('event'));
        $this->assertSame(SubjectConversationMode::Reply, $settings->subjectConversationMode('game-table'));
        $this->assertSame(SubjectConversationMode::Hidden, $settings->subjectConversationMode('campaign'));
        // A type a later module brings starts hidden
        $this->assertSame(SubjectConversationMode::Hidden, $settings->subjectConversationMode('tournament'));
    }

    public function test_an_unreadable_conversation_mode_hides_the_conversation(): void
    {
        config(['modules.settings.forum' => ['subject_conversations' => ['event' => 'everything', 'game-table' => 1]]]);
        $settings = new ForumSettings;

        $this->assertSame(SubjectConversationMode::Hidden, $settings->subjectConversationMode('event'));
        $this->assertSame(SubjectConversationMode::Hidden, $settings->subjectConversationMode('game-table'));

        config(['modules.settings.forum' => ['subject_conversations' => 'reply']]);
        $this->assertSame(SubjectConversationMode::Hidden, (new ForumSettings)->subjectConversationMode('event'));
    }
}
