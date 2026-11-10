<?php

declare(strict_types=1);

namespace Modules\Forum\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Forum\Application\Services\DefaultCategoriesInstaller;

/**
 * Creates the categories a new forum starts with when the module is first enabled. The
 * installer only adds what is missing.
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

        // Resolved here, not in the constructor: seeders are built before the provider binds
        app(DefaultCategoriesInstaller::class)->install();
    }
}
