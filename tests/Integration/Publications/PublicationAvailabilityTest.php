<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Publications;

use App\Application\Publications\Contracts\PublicationSourceInterface;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use Modules\Forum\Application\Publications\PublicationAvailability;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Tests\Support\FakeCampaignSource;
use Modules\Forum\Tests\Support\FakeGameTableSource;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

/**
 * What the forum builds on other modules' publications exists only while the core's registry has a
 * source for their type; the forum never asks which module is enabled (forum#49).
 */
final class PublicationAvailabilityTest extends ModuleTestCase
{
    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_without_game_tables_only_the_events_category_is_offered(): void
    {
        $availability = $this->availability();

        $this->assertTrue($availability->offers('event'));
        $this->assertFalse($availability->offers(FakeGameTableSource::TYPE));
        $this->assertFalse($availability->offers(FakeCampaignSource::TYPE));
        $this->assertSame([SystemCategory::Events], $availability->systemCategories());
    }

    public function test_the_categories_of_a_registered_source_are_offered(): void
    {
        $this->registerGameTables();

        $this->assertSame(SystemCategory::cases(), $this->availability()->systemCategories());
    }

    public function test_a_plain_category_is_always_available(): void
    {
        $this->assertTrue($this->availability()->isAvailable($this->plain()));
    }

    public function test_a_system_category_is_available_while_its_source_is(): void
    {
        $tables = $this->system(SystemCategory::GameTables);
        $events = $this->system(SystemCategory::Events);

        $this->assertFalse($this->availability()->isAvailable($tables));
        $this->assertTrue($this->availability()->isAvailable($events));

        $this->registerGameTables();
        $this->assertTrue($this->availability()->isAvailable($tables));

        app(PublicationSourceRegistryInterface::class)->unregisterModule('example');
        $this->assertFalse($this->availability()->isAvailable($tables));
    }

    public function test_a_campaign_space_and_anything_under_a_hidden_category_follow_the_source(): void
    {
        $campaigns = $this->system(SystemCategory::Campaigns);
        $space = $this->plain(parent: $campaigns, subject: new CategorySubject(FakeCampaignSource::TYPE, 'c-1'));
        $plainChild = $this->plain(parent: $campaigns);

        $this->assertFalse($this->availability()->isAvailable($space, $campaigns));
        $this->assertFalse($this->availability()->isAvailable($plainChild, $campaigns));

        $this->registerGameTables();

        $this->assertTrue($this->availability()->isAvailable($space, $campaigns));
        $this->assertTrue($this->availability()->isAvailable($plainChild, $campaigns));
    }

    public function test_a_registry_that_fails_hides_what_depends_on_it_and_breaks_nothing(): void
    {
        $this->app->instance(PublicationSourceRegistryInterface::class, new class implements PublicationSourceRegistryInterface
        {
            public function register(string $sourceClass, ?string $module = null): void {}

            public function registerMany(array $sourceClasses, ?string $module = null): void {}

            public function all(): array
            {
                return [];
            }

            public function sources(): array
            {
                throw new RuntimeException('registry down');
            }

            public function source(string $type): ?PublicationSourceInterface
            {
                throw new RuntimeException('registry down');
            }

            public function unregisterModule(string $module): void {}

            public function clear(): void {}
        });

        $availability = $this->availability();

        $this->assertFalse($availability->isAvailable($this->system(SystemCategory::Events)));
        $this->assertTrue($availability->isAvailable($this->plain()));
        $this->assertSame([], $availability->systemCategories());
    }

    private function registerGameTables(): void
    {
        app(PublicationSourceRegistryInterface::class)->register(FakeGameTableSource::class, 'example');
        app(PublicationSourceRegistryInterface::class)->register(FakeCampaignSource::class, 'example');
    }

    private function system(SystemCategory $key): Category
    {
        return Category::system(CategoryId::generate(), $key, ucfirst($key->value), new CategorySlug($key->value), 0);
    }

    private function plain(?Category $parent = null, ?CategorySubject $subject = null): Category
    {
        return new Category(
            CategoryId::generate(),
            'General',
            new CategorySlug('general'),
            parentId: $parent?->id(),
            viewAudience: $subject instanceof CategorySubject ? CategoryAudience::roster() : null,
            writeAudience: $subject instanceof CategorySubject ? CategoryAudience::roster() : null,
            subject: $subject,
        );
    }

    /**
     * As a new request: the answers of the registry are remembered per request.
     */
    private function availability(): PublicationAvailability
    {
        $this->app->forgetScopedInstances();

        return app(PublicationAvailability::class);
    }
}
