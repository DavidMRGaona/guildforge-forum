<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Publications;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\DTOs\PublicationRosterDTO;
use App\Application\Publications\Enums\PublicationState;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Bus;
use Modules\Forum\Application\Publications\CampaignSpaceSync;
use Modules\Forum\Application\Publications\PublicationDiscussionReconciler;
use Modules\Forum\Application\Publications\PublicationDiscussionSync;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Application\Services\DefaultCategoriesInstaller;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryAudienceKind;
use Modules\Forum\Domain\Enums\ReadOnlyOrigin;
use Modules\Forum\Domain\Enums\RosterScope;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Filament\Resources\CategoryResource\CategoryForm;
use Modules\Forum\Infrastructure\Jobs\SyncPublicationDiscussionJob;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use Modules\Forum\Tests\Support\FakeCampaignSource;
use Modules\Forum\Tests\Support\FakeGameTableSource;
use Modules\Forum\Tests\Support\FakePublicationSource;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Each campaign has a private space for its roster, opened and closed with the campaign, with
 * access asked to the roster each time and failing closed.
 */
final class CampaignSpaceSyncTest extends ModuleTestCase
{
    use UsesUtcStorage;

    private const string ID = '7d3c1e8a-2b4f-4c6d-9e0a-1f2b3c4d5e6f';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $player;

    private UserModel $director;

    private UserModel $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->travelTo(new DateTimeImmutable('2026-11-20 10:00:00 UTC'));

        FakePublicationSource::reset();
        FakeCampaignSource::resetRosters();
        app(PublicationSourceRegistryInterface::class)->register(FakeCampaignSource::class, 'example');
        app(PublicationSourceRegistryInterface::class)->register(FakeGameTableSource::class, 'example');
        app(DefaultCategoriesInstaller::class)->install();

        $this->player = UserModel::factory()->create();
        $this->director = UserModel::factory()->create();
        $this->outsider = UserModel::factory()->create();
        FakeCampaignSource::$rosters[self::ID] = new PublicationRosterDTO([$this->player->id], [$this->director->id]);
    }

    protected function tearDown(): void
    {
        FakePublicationSource::reset();
        FakeCampaignSource::resetRosters();

        parent::tearDown();
    }

    public function test_an_active_campaign_gets_a_private_space_in_campaigns(): void
    {
        $this->publish();
        $this->sync();

        $space = $this->space();
        $campaigns = app(CategoryRepositoryInterface::class)->findBySystemKey(SystemCategory::Campaigns);
        $this->assertInstanceOf(Category::class, $campaigns);
        $this->assertTrue($space->parentId()?->equals($campaigns->id()));
        $this->assertTrue($space->isPublicationSpace());
        $this->assertSame('La caída de Arrakis', $space->name());
        $this->assertSame(CategoryAudienceKind::Roster, $space->viewAudience()->kind);
        $this->assertSame(CategoryAudienceKind::Roster, $space->writeAudience()->kind);
        $this->assertSame(RosterScope::Leaders, $space->moderators()->roster);
        $this->assertFalse($space->isReadOnly());

        $this->sync();
        $this->assertSame(1, CategoryModel::query()->where('subject_type', 'campaign')->count());
    }

    public function test_its_roster_reads_and_writes_and_its_directors_moderate(): void
    {
        $this->publish();
        $this->sync();
        $space = $this->space();

        $this->assertTrue($this->policy()->canWrite($this->player, $space));
        $this->assertFalse($this->policy()->canModerate($this->player, $space));
        $this->assertTrue($this->policy()->canWrite($this->director, $space));
        $this->assertTrue($this->policy()->canModerate($this->director, $space));
        $this->assertFalse($this->policy()->canView($this->outsider, $space));
        $this->assertFalse($this->policy()->canView(null, $space));
        $this->assertTrue($this->policy()->canWrite($this->forumModerator(), $space));
    }

    public function test_joining_the_campaign_lets_in_at_once_without_syncing_anything(): void
    {
        $this->publish();
        $this->sync();

        FakeCampaignSource::$rosters[self::ID] = new PublicationRosterDTO([$this->player->id, $this->outsider->id], [$this->director->id]);

        $this->assertTrue($this->freshPolicy()->canWrite($this->outsider, $this->space()));
    }

    public function test_a_roster_that_cannot_be_read_lets_in_only_the_forum_moderators(): void
    {
        $this->publish();
        $this->sync();

        FakeCampaignSource::$rosterFails = true;

        $this->assertFalse($this->freshPolicy()->canView($this->player, $this->space()));
        $this->assertFalse($this->freshPolicy()->canView($this->director, $this->space()));
        $this->assertTrue($this->freshPolicy()->canView($this->forumModerator(), $this->space()));
    }

    public function test_a_campaign_over_or_cancelled_before_its_space_never_gets_one(): void
    {
        $this->publish(PublicationState::Cancelled);
        $this->sync();
        $this->publish(PublicationState::Ended);
        $this->sync();

        $this->assertSame(0, CategoryModel::query()->where('subject_type', 'campaign')->count());
    }

    public function test_an_ended_campaign_leaves_its_space_read_only_for_its_roster(): void
    {
        $this->publish();
        $this->sync();
        $this->publish(PublicationState::Ended);
        $this->sync();

        $space = $this->space();
        $this->assertSame(ReadOnlyOrigin::System, $space->readOnlyBy());
        $this->assertTrue($this->freshPolicy()->canView($this->player, $space));
        $this->assertFalse($this->freshPolicy()->canWrite($this->player, $space));
        $this->assertFalse($this->freshPolicy()->canWrite($this->director, $space));
        $this->assertTrue($this->freshPolicy()->canModerate($this->director, $space));
        $this->assertTrue($this->freshPolicy()->canWrite($this->forumModerator(), $space));
    }

    public function test_a_cancelled_campaign_is_read_only_until_it_is_active_again(): void
    {
        $this->publish();
        $this->sync();
        $this->publish(PublicationState::Cancelled);
        $this->sync();

        $this->assertTrue($this->space()->isReadOnly());

        $this->publish();
        $this->sync();

        $this->assertFalse($this->space()->isReadOnly());
        $this->assertTrue($this->freshPolicy()->canWrite($this->player, $this->space()));
    }

    public function test_read_only_set_by_moderation_is_never_lifted_by_the_forum(): void
    {
        $this->publish();
        $this->sync();
        $this->moderationMakesReadOnly();

        $this->publish(PublicationState::Cancelled);
        $this->sync();
        $this->publish();
        $this->sync();

        $this->assertSame(ReadOnlyOrigin::Moderation, $this->space()->readOnlyBy());
        $this->assertFalse($this->freshPolicy()->canWrite($this->player, $this->space()));
    }

    public function test_read_only_set_by_moderation_on_an_active_campaign_stays(): void
    {
        $this->publish();
        $this->sync();
        $this->moderationMakesReadOnly();

        $this->sync();

        $this->assertSame(ReadOnlyOrigin::Moderation, $this->space()->readOnlyBy());
    }

    public function test_a_campaign_taken_down_is_seen_only_by_the_forum_moderators_until_published_again(): void
    {
        $this->publish();
        $this->sync();
        $spaceId = $this->space()->id();

        FakePublicationSource::forget(FakeCampaignSource::TYPE, self::ID);
        $this->sync();

        $space = $this->space();
        $this->assertTrue($space->isReadOnly());
        $this->assertFalse($this->freshPolicy()->canView($this->player, $space));
        $this->assertFalse($this->freshPolicy()->canView($this->director, $space));
        $this->assertTrue($this->freshPolicy()->canView($this->forumModerator(), $space));

        $this->publish();
        $this->sync();

        $this->assertTrue($this->space()->id()->equals($spaceId));
        $this->assertFalse($this->space()->isReadOnly());
        $this->assertTrue($this->freshPolicy()->canWrite($this->player, $this->space()));
    }

    public function test_a_deleted_campaign_keeps_its_space_for_the_forum_moderators_only(): void
    {
        $this->publish();
        $this->sync();

        FakePublicationSource::forget(FakeCampaignSource::TYPE, self::ID);
        unset(FakeCampaignSource::$rosters[self::ID]);
        app(SyncPublicationDiscussionJob::class, ['type' => FakeCampaignSource::TYPE, 'id' => self::ID, 'removed' => true])->handle(
            app(PublicationDiscussionSync::class),
            app(CampaignSpaceSync::class),
        );

        $this->assertSame(1, CategoryModel::query()->where('subject_type', 'campaign')->count());
        $this->assertFalse($this->freshPolicy()->canView($this->director, $this->space()));
        $this->assertTrue($this->freshPolicy()->canView($this->forumModerator(), $this->space()));
    }

    public function test_the_space_is_named_after_the_campaign(): void
    {
        $this->publish();
        $this->sync();
        $this->publish(title: 'El regreso a Arrakis');
        $this->sync();

        $this->assertSame('El regreso a Arrakis', $this->space()->name());
    }

    public function test_a_failing_source_fails_the_check_and_changes_nothing(): void
    {
        $this->publish();
        $this->sync();

        FakePublicationSource::$failing[FakeCampaignSource::TYPE] = true;

        try {
            $this->sync();
            $this->fail('The check should fail so the job is retried.');
        } catch (RuntimeException) {
            unset(FakePublicationSource::$failing[FakeCampaignSource::TYPE]);
        }

        $this->assertFalse($this->space()->isReadOnly());
    }

    public function test_game_tables_get_no_space(): void
    {
        FakePublicationSource::put(new PublicationDTO(FakeGameTableSource::TYPE, self::ID, 'Mesa', '', 'https://example.test/mesas/1', PublicationState::Active));

        app(CampaignSpaceSync::class)->sync(new PublicationKey(FakeGameTableSource::TYPE, self::ID));

        $this->assertSame(0, CategoryModel::query()->whereNotNull('subject_type')->count());
    }

    public function test_the_reconciliation_checks_campaigns_whose_space_still_takes_posts(): void
    {
        $this->publish();
        $this->sync();
        FakePublicationSource::$listed = [FakeCampaignSource::TYPE => []];

        Bus::fake([SyncPublicationDiscussionJob::class]);
        app(PublicationDiscussionReconciler::class)->reconcile();

        Bus::assertDispatched(SyncPublicationDiscussionJob::class, static fn (SyncPublicationDiscussionJob $job): bool => $job->id === self::ID);
    }

    public function test_a_read_only_parent_makes_its_subcategories_read_only(): void
    {
        $this->publish();
        $this->sync();

        $campaigns = app(CategoryRepositoryInterface::class)->findBySystemKey(SystemCategory::Campaigns);
        $this->assertInstanceOf(Category::class, $campaigns);
        $campaigns->makeReadOnly();
        app(CategoryManager::class)->save($campaigns);

        $this->assertFalse($this->freshPolicy()->canWrite($this->player, $this->space()));
        $this->assertTrue($this->freshPolicy()->canView($this->player, $this->space()));
    }

    public function test_the_panel_sets_read_only_as_moderation_and_leaves_the_forums_mark_alone(): void
    {
        $this->publish();
        $this->sync();
        $form = app(CategoryForm::class);
        $record = CategoryModel::query()->findOrFail($this->space()->id()->value);

        $form->save([...$form->fill($record), 'read_only' => true], $record->id);
        $this->assertSame(ReadOnlyOrigin::Moderation, $this->space()->readOnlyBy());

        $form->save([...$form->fill($record->fresh() ?? $record), 'read_only' => false], $record->id);
        $this->assertFalse($this->space()->isReadOnly());

        $this->publish(PublicationState::Ended);
        $this->sync();
        $record = CategoryModel::query()->findOrFail($record->id);

        // The toggle is disabled then: whatever it sends, the forum's mark stays
        $form->save([...$form->fill($record), 'read_only' => false], $record->id);
        $this->assertSame(ReadOnlyOrigin::System, $this->space()->readOnlyBy());
    }

    private function publish(PublicationState $state = PublicationState::Active, string $title = 'La caída de Arrakis'): void
    {
        FakePublicationSource::put(new PublicationDTO(
            type: FakeCampaignSource::TYPE,
            id: self::ID,
            title: $title,
            summary: 'Campaña de Dune.',
            url: 'https://example.test/campanas/arrakis',
            state: $state,
        ));
    }

    private function sync(): void
    {
        app(CampaignSpaceSync::class)->sync(new PublicationKey(FakeCampaignSource::TYPE, self::ID));
    }

    private function space(): Category
    {
        $space = app(CategoryRepositoryInterface::class)->findBySubject(new CategorySubject(FakeCampaignSource::TYPE, self::ID));
        $this->assertInstanceOf(Category::class, $space);

        return $space;
    }

    private function moderationMakesReadOnly(): void
    {
        $space = $this->space();
        $space->makeReadOnly();
        app(CategoryManager::class)->save($space);
    }

    private function forumModerator(): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'moderation-'.strtolower(uniqid()), 'display_name' => 'Moderación']);
        $permission = PermissionModel::query()->firstOrCreate(['key' => CategoryAccessPolicy::MODERATE_FORUM], ['label' => 'moderate', 'resource' => 'forum', 'action' => 'moderate', 'module' => 'forum']);
        $role->permissions()->attach($permission->id);
        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($user);

        return $user;
    }

    private function policy(): CategoryAccessPolicy
    {
        return app(CategoryAccessPolicy::class);
    }

    private function freshPolicy(): CategoryAccessPolicy
    {
        $this->app->forgetScopedInstances();

        return app(CategoryAccessPolicy::class);
    }
}
