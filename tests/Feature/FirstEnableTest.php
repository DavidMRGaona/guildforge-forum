<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature;

use App\Application\Services\SettingsServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Updates\Persistence\Eloquent\Models\ModuleSeederHistoryModel;
use App\Modules\ModuleLoader;
use Modules\Forum\Database\Seeders\DefaultCategoriesSeeder;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Infrastructure\Services\EloquentSystemAccount;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Enabling the forum for the first time runs its seeders before the module is enabled, while
 * its provider has not booted yet: they must still leave the default categories and the
 * system account in place (forum#27).
 */
final class FirstEnableTest extends ModuleTestCase
{
    protected ?string $moduleName = 'forum';

    protected function tearDown(): void
    {
        DefaultCategoriesSeeder::$seedInTests = false;

        parent::tearDown();
    }

    public function test_enabling_the_forum_for_the_first_time_installs_its_categories_and_system_account(): void
    {
        // Seeders are not autoloaded: the runner requires them while enabling, so the flag needs the class first
        require_once dirname(__DIR__, 2).'/database/seeders/DefaultCategoriesSeeder.php';
        DefaultCategoriesSeeder::$seedInTests = true;
        $this->discoverModules();
        $this->assertFalse(app(ModuleLoader::class)->isLoaded('forum'), 'The provider must not be booted before enabling.');

        $module = $this->enableModule('forum');

        $this->assertTrue($module->isEnabled());
        $this->assertTrue($module->isInstalled());
        $this->assertTrue(ModuleSeederHistoryModel::wasExecuted('forum', DefaultCategoriesSeeder::class));

        $names = array_map(static fn (Category $category): string => $category->name(), app(CategoryRepositoryInterface::class)->all());
        sort($names);
        $this->assertSame(['Campañas', 'Eventos', 'General', 'Mesas', 'Partidas por foro'], $names);

        $accountId = app(SettingsServiceInterface::class)->get(EloquentSystemAccount::SETTING);
        $this->assertIsString($accountId);
        $this->assertTrue(UserModel::query()->whereKey($accountId)->exists());
    }
}
