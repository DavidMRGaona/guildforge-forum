<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Publications;

use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\Enums\PublicationState;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Domain\Modules\Events\ModuleEnabled;
use DateTimeImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Modules\Forum\Application\Publications\CampaignSpaceSync;
use Modules\Forum\Application\Publications\PublicationDiscussionSync;
use Modules\Forum\Application\Services\DefaultCategoriesInstaller;
use Modules\Forum\Console\Commands\SyncPublicationDiscussionsCommand;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Infrastructure\Jobs\SyncPublicationDiscussionJob;
use Modules\Forum\Infrastructure\Jobs\SyncPublicationsAfterModuleChangeJob;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Tests\Support\FakeCampaignSource;
use Modules\Forum\Tests\Support\FakeGameTableSource;
use Modules\Forum\Tests\Support\FakePublicationSource;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * A forum enabled before game tables: «Mesas» and «Campañas» come when a module offers their
 * publications, through the daily reconciliation, after any module is enabled, and the moment a
 * publication needs them (forum#49).
 */
final class ArrivingSourcesTest extends ModuleTestCase
{
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->travelTo(new DateTimeImmutable('2026-11-20 10:00:00 UTC'));
        FakePublicationSource::reset();
        app(DefaultCategoriesInstaller::class)->install();
    }

    protected function tearDown(): void
    {
        FakePublicationSource::reset();
        FakeCampaignSource::resetRosters();

        parent::tearDown();
    }

    public function test_the_daily_reconciliation_creates_the_categories_of_a_source_that_came(): void
    {
        $this->runReconciliation();
        $this->assertNull($this->categories()->findBySystemKey(SystemCategory::GameTables));

        $this->registerGameTables();
        Bus::fake([SyncPublicationDiscussionJob::class]);
        $this->runReconciliation();

        $this->assertInstanceOf(Category::class, $this->categories()->findBySystemKey(SystemCategory::GameTables));
        $this->assertInstanceOf(Category::class, $this->categories()->findBySystemKey(SystemCategory::Campaigns));
    }

    public function test_enabling_any_module_queues_the_reconciliation(): void
    {
        Bus::fake([SyncPublicationsAfterModuleChangeJob::class]);

        event(new ModuleEnabled('id', 'some-module'));

        Bus::assertDispatched(SyncPublicationsAfterModuleChangeJob::class);
    }

    public function test_the_job_runs_the_reconciliation_in_a_fresh_process(): void
    {
        // The worker booted with the modules enabled then: its registry lacks the new sources
        Process::fake();

        (new SyncPublicationsAfterModuleChangeJob)->handle();

        Process::assertRan(static fn (PendingProcess $process): bool => is_array($process->command) && in_array('forum:sync-publications', $process->command, true));
    }

    public function test_a_failing_reconciliation_process_breaks_nothing(): void
    {
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        (new SyncPublicationsAfterModuleChangeJob)->handle();

        Process::assertRanTimes(static fn (): bool => true, 1);
    }

    public function test_a_publication_whose_category_is_missing_creates_it(): void
    {
        $this->registerGameTables();
        FakePublicationSource::put(new PublicationDTO(
            type: FakeGameTableSource::TYPE,
            id: 'a1',
            title: 'Mesa a1',
            summary: '',
            url: 'https://example.test/mesas/a1',
            state: PublicationState::Active,
        ));

        app(PublicationDiscussionSync::class)->sync(new PublicationKey(FakeGameTableSource::TYPE, 'a1'));

        $tables = $this->categories()->findBySystemKey(SystemCategory::GameTables);
        $this->assertInstanceOf(Category::class, $tables);
        $this->assertSame(1, DiscussionModel::query()->where('category_id', $tables->id()->value)->count());
    }

    public function test_a_campaign_whose_category_is_missing_creates_it_with_its_space(): void
    {
        $this->registerGameTables();
        FakePublicationSource::put(new PublicationDTO(
            type: FakeCampaignSource::TYPE,
            id: '7d3c1e8a-2b4f-4c6d-9e0a-1f2b3c4d5e6f',
            title: 'La tumba',
            summary: '',
            url: 'https://example.test/campanas/la-tumba',
            state: PublicationState::Active,
        ));

        app(CampaignSpaceSync::class)->sync(new PublicationKey(FakeCampaignSource::TYPE, '7d3c1e8a-2b4f-4c6d-9e0a-1f2b3c4d5e6f'));

        $campaigns = $this->categories()->findBySystemKey(SystemCategory::Campaigns);
        $this->assertInstanceOf(Category::class, $campaigns);
        $this->assertCount(1, $this->categories()->childrenOf($campaigns->id()));
    }

    public function test_a_type_without_source_creates_nothing(): void
    {
        app(PublicationDiscussionSync::class)->sync(new PublicationKey(FakeGameTableSource::TYPE, 'a1'));
        app(CampaignSpaceSync::class)->sync(new PublicationKey(FakeCampaignSource::TYPE, '7d3c1e8a-2b4f-4c6d-9e0a-1f2b3c4d5e6f'));

        $this->assertNull($this->categories()->findBySystemKey(SystemCategory::GameTables));
        $this->assertNull($this->categories()->findBySystemKey(SystemCategory::Campaigns));
    }

    private function registerGameTables(): void
    {
        app(PublicationSourceRegistryInterface::class)->register(FakeGameTableSource::class, 'example');
        app(PublicationSourceRegistryInterface::class)->register(FakeCampaignSource::class, 'example');
        $this->app->forgetScopedInstances();
    }

    private function runReconciliation(): void
    {
        $command = app(SyncPublicationDiscussionsCommand::class);
        $command->setLaravel($this->app);
        $this->assertSame(0, $command->run(new ArrayInput([]), new BufferedOutput));
    }

    private function categories(): CategoryRepositoryInterface
    {
        return app(CategoryRepositoryInterface::class);
    }
}
