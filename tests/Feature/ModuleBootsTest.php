<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Modules\Forum\Application\Contracts\ForumCountersInterface;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\ForumServiceProvider;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentCategoryRepository;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentDiscussionRepository;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentPostRepository;
use Modules\Forum\Infrastructure\Services\EloquentForumCounters;
use Modules\Forum\Infrastructure\Services\ForumSettings;
use Tests\Support\Modules\ModuleTestCase;

final class ModuleBootsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_module_is_enabled_and_its_provider_is_loaded(): void
    {
        $module = $this->findModule('forum');

        $this->assertNotNull($module);
        $this->assertTrue($module->isEnabled());
        $this->assertNotNull($this->app->getProvider(ForumServiceProvider::class));
    }

    public function test_manifest_names_the_module_and_stores_utc(): void
    {
        /** @var array{name: string, displayName: string, repository: string, requires: array{core: string}, storesUtc: bool} $manifest */
        $manifest = json_decode((string) file_get_contents(__DIR__.'/../../module.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('forum', $manifest['name']);
        $this->assertSame('Foro', $manifest['displayName']);
        $this->assertSame('DavidMRGaona/guildforge-forum', $manifest['repository']);
        $this->assertSame('^3.53', $manifest['requires']['core']);
        $this->assertTrue($manifest['storesUtc']);
    }

    public function test_the_domain_interfaces_are_bound_to_their_implementations(): void
    {
        $this->assertInstanceOf(EloquentCategoryRepository::class, app(CategoryRepositoryInterface::class));
        $this->assertInstanceOf(EloquentDiscussionRepository::class, app(DiscussionRepositoryInterface::class));
        $this->assertInstanceOf(EloquentPostRepository::class, app(PostRepositoryInterface::class));
        $this->assertInstanceOf(EloquentForumCounters::class, app(ForumCountersInterface::class));
        $this->assertInstanceOf(ForumSettings::class, app(ForumSettingsInterface::class));
    }

    public function test_its_tables_carry_the_forum_prefix_and_nothing_of_the_old_bridge(): void
    {
        foreach (['forum_categories', 'forum_discussions', 'forum_posts'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is missing.");
        }

        $this->assertSame([], array_values(array_filter(
            array_map(static fn (array $table): string => (string) $table['name'], Schema::getTables()),
            static fn (string $name): bool => str_starts_with($name, 'forum_bridge'),
        )));
    }

    public function test_posts_keep_the_document_and_a_plain_text_copy(): void
    {
        $this->assertTrue(Schema::hasColumns('forum_posts', ['body', 'plain_text', 'number', 'status', 'edited_at']));
    }
}
