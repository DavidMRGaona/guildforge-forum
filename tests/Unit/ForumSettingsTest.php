<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit;

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
}
