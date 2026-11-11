<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Publications;

use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\Enums\PublicationState;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Domain\Publications\Events\PublicationChanged;
use App\Domain\Publications\Events\PublicationRemoved;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Modules\Forum\Application\Contracts\PublicationSyncQueueInterface;
use Modules\Forum\Application\Publications\PublicationDiscussionSync;
use Modules\Forum\Application\Services\DefaultCategoriesInstaller;
use Modules\Forum\Console\Commands\SyncPublicationDiscussionsCommand;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Infrastructure\Jobs\SyncPublicationDiscussionJob;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Tests\Support\FakeGameTableSource;
use Modules\Forum\Tests\Support\FakePublicationSource;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Changes reach the discussion through the queue, never failing the save on the site, and a
 * daily reconciliation catches up with what was published before or lost on the way.
 */
final class PublicationDiscussionQueueTest extends ModuleTestCase
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
        app(PublicationSourceRegistryInterface::class)->register(FakeGameTableSource::class, 'example');
        app(DefaultCategoriesInstaller::class)->install();
    }

    protected function tearDown(): void
    {
        FakePublicationSource::reset();

        parent::tearDown();
    }

    public function test_a_change_queues_a_check_of_that_publication(): void
    {
        Bus::fake([SyncPublicationDiscussionJob::class]);

        event(new PublicationChanged(FakeGameTableSource::TYPE, 'a1'));
        event(new PublicationRemoved(FakeGameTableSource::TYPE, 'a2'));

        Bus::assertDispatched(SyncPublicationDiscussionJob::class, static fn (SyncPublicationDiscussionJob $job): bool => $job->id === 'a1' && ! $job->removed);
        Bus::assertDispatched(SyncPublicationDiscussionJob::class, static fn (SyncPublicationDiscussionJob $job): bool => $job->id === 'a2' && $job->removed);
    }

    public function test_types_the_forum_does_not_announce_queue_nothing(): void
    {
        Bus::fake([SyncPublicationDiscussionJob::class]);

        event(new PublicationChanged('survey', 'a1'));

        Bus::assertNotDispatched(SyncPublicationDiscussionJob::class);
    }

    public function test_publishing_never_fails_because_of_the_forum(): void
    {
        $this->expectNotToPerformAssertions();

        $this->app->instance(PublicationSyncQueueInterface::class, new class implements PublicationSyncQueueInterface
        {
            public function queue(PublicationKey $key, bool $removed = false): void
            {
                throw new RuntimeException('Queue down');
            }

            public function queueAt(PublicationKey $key, DateTimeImmutable $at): void
            {
                throw new RuntimeException('Queue down');
            }
        });

        event(new PublicationChanged(FakeGameTableSource::TYPE, 'a1'));
    }

    public function test_the_job_opens_the_discussion_when_it_runs(): void
    {
        $this->publish('a1');

        event(new PublicationChanged(FakeGameTableSource::TYPE, 'a1'));

        $this->assertSame(1, DiscussionModel::query()->count());
    }

    public function test_a_job_whose_source_fails_fails_so_it_is_retried(): void
    {
        FakePublicationSource::$failing[FakeGameTableSource::TYPE] = true;

        $this->expectException(RuntimeException::class);

        (new SyncPublicationDiscussionJob(FakeGameTableSource::TYPE, 'a1'))->handle(app(PublicationDiscussionSync::class));
    }

    public function test_the_reconciliation_checks_published_ones_and_those_the_forum_still_follows(): void
    {
        $this->publish('a1');
        $this->publish('a2');
        app(PublicationDiscussionSync::class)->sync(new PublicationKey(FakeGameTableSource::TYPE, 'a2'));

        // a2 is no longer listed (taken down meanwhile); a3 is new
        FakePublicationSource::$listed = [FakeGameTableSource::TYPE => ['a1', 'a3']];

        Bus::fake([SyncPublicationDiscussionJob::class]);
        $this->runReconciliation();

        $ids = [];
        Bus::assertDispatched(SyncPublicationDiscussionJob::class, static function (SyncPublicationDiscussionJob $job) use (&$ids): bool {
            $ids[] = $job->id;

            return true;
        });
        sort($ids);
        $this->assertSame(['a1', 'a2', 'a3'], $ids);
    }

    public function test_a_listing_that_fails_is_skipped(): void
    {
        FakePublicationSource::$failing[FakeGameTableSource::TYPE] = true;

        Bus::fake([SyncPublicationDiscussionJob::class]);
        $this->runReconciliation();

        Bus::assertNotDispatched(SyncPublicationDiscussionJob::class);
    }

    public function test_the_reconciliation_runs_daily(): void
    {
        $commands = array_map(static fn ($event): string => (string) $event->command, app(Schedule::class)->events());

        $this->assertNotEmpty(array_filter($commands, static fn (string $command): bool => str_contains($command, 'forum:sync-publications')));
    }

    private function runReconciliation(): void
    {
        $command = app(SyncPublicationDiscussionsCommand::class);
        $command->setLaravel($this->app);
        $this->assertSame(0, $command->run(new ArrayInput([]), new BufferedOutput));
    }

    private function publish(string $id): void
    {
        FakePublicationSource::put(new PublicationDTO(
            type: FakeGameTableSource::TYPE,
            id: $id,
            title: "Mesa {$id}",
            summary: '',
            url: "https://example.test/mesas/{$id}",
            state: PublicationState::Active,
        ));
    }
}
