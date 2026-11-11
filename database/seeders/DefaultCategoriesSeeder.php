<?php

declare(strict_types=1);

namespace Modules\Forum\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Services\DefaultCategoriesInstaller;

/**
 * Creates the categories a new forum starts with, and the system account its automatic
 * discussions are written with, when the module is first enabled. Only what is missing is added.
 */
final class DefaultCategoriesSeeder extends Seeder
{
    /**
     * Enabling the module in a test runs every seeder, and module tests expect no categories.
     * Only the tests of this seeder turn it on.
     */
    public static bool $seedInTests = false;

    public function run(): void
    {
        if (app()->runningUnitTests() && ! self::$seedInTests) {
            return;
        }

        app(DefaultCategoriesInstaller::class)->install();
        app(SystemAccountInterface::class)->id();
    }
}
