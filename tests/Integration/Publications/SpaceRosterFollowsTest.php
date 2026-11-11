<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Publications;

use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\DTOs\PublicationRosterDTO;
use App\Application\Publications\Enums\PublicationState;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Application\SiteNotifications\Services\SiteNotificationServiceInterface;
use App\Domain\Publications\Events\PublicationRosterChanged;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Publications\CampaignSpaceSync;
use Modules\Forum\Application\Publications\SpaceRosterFollows;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Application\Services\DefaultCategoriesInstaller;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryFollowOrigin;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Tests\Support\FakeCampaignSource;
use Modules\Forum\Tests\Support\FakePublicationSource;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Joining a campaign's roster follows its space and says so, once; leaving it ends that follow.
 * The roster is read again each time, never taken from the event.
 */
final class SpaceRosterFollowsTest extends ModuleTestCase
{
    use UsesUtcStorage;

    private const string ID = '7d3c1e8a-2b4f-4c6d-9e0a-1f2b3c4d5e6f';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $player;

    private UserModel $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->travelTo(new DateTimeImmutable('2026-11-20 10:00:00 UTC'));

        FakePublicationSource::reset();
        FakeCampaignSource::resetRosters();
        app(PublicationSourceRegistryInterface::class)->register(FakeCampaignSource::class, 'example');
        app(DefaultCategoriesInstaller::class)->install();

        $this->player = UserModel::factory()->create();
        $this->director = UserModel::factory()->create();
        FakeCampaignSource::$rosters[self::ID] = new PublicationRosterDTO([strtolower($this->player->id)], [strtolower($this->director->id)]);
        FakePublicationSource::put(new PublicationDTO(
            type: FakeCampaignSource::TYPE,
            id: self::ID,
            title: 'La caída de Arrakis',
            summary: 'Campaña de Dune.',
            url: 'https://example.test/campanas/arrakis',
            state: PublicationState::Active,
        ));
    }

    protected function tearDown(): void
    {
        FakePublicationSource::reset();
        FakeCampaignSource::resetRosters();

        parent::tearDown();
    }

    public function test_the_roster_of_a_new_space_follows_it_and_hears_it_can_enter(): void
    {
        $this->openSpace();

        foreach ([$this->player, $this->director] as $user) {
            $this->assertSame(CategoryFollowOrigin::Roster, $this->follows()->categoryFollowOrigin(strtolower($user->id), $this->space()->id()));
            $items = app(SiteNotificationServiceInterface::class)->latest($user, 5);
            $this->assertCount(1, $items);
            $this->assertSame('Te han añadido a la campaña «La caída de Arrakis»: ya puedes entrar en su espacio del foro', $items[0]->title);
        }
    }

    public function test_joining_follows_the_space_and_tells_once_even_if_the_change_arrives_twice(): void
    {
        $this->openSpace();
        $newcomer = UserModel::factory()->create();
        FakeCampaignSource::$rosters[self::ID] = new PublicationRosterDTO([strtolower($this->player->id), strtolower($newcomer->id)], [strtolower($this->director->id)]);
        // A queue worker starts each job with fresh rules; the sync queue of the tests does not
        app()->forgetScopedInstances();

        event(new PublicationRosterChanged(FakeCampaignSource::TYPE, self::ID, [$newcomer->id]));
        event(new PublicationRosterChanged(FakeCampaignSource::TYPE, self::ID, [$newcomer->id]));

        $this->assertTrue($this->follows()->followsCategory(strtolower($newcomer->id), $this->space()->id()));
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->total($newcomer));
        // Those whose place did not change hear nothing more
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->total($this->player));
    }

    public function test_leaving_ends_the_follow_the_roster_gave_but_not_one_the_person_chose(): void
    {
        $this->openSpace();
        $this->follows()->unfollowCategory(strtolower($this->director->id), $this->space()->id());
        $this->follows()->followCategory(strtolower($this->director->id), $this->space()->id(), CategoryFollowOrigin::Person, CarbonImmutable::now());
        FakeCampaignSource::$rosters[self::ID] = new PublicationRosterDTO([], []);

        event(new PublicationRosterChanged(FakeCampaignSource::TYPE, self::ID, [$this->player->id, $this->director->id]));

        $this->assertFalse($this->follows()->followsCategory(strtolower($this->player->id), $this->space()->id()));
        $this->assertTrue($this->follows()->followsCategory(strtolower($this->director->id), $this->space()->id()));
    }

    public function test_joining_a_space_that_takes_no_posts_follows_nothing(): void
    {
        $this->openSpace();
        $space = $this->space();
        $space->makeReadOnly();
        app(CategoryManager::class)->save($space);
        $newcomer = UserModel::factory()->create();
        FakeCampaignSource::$rosters[self::ID] = new PublicationRosterDTO([strtolower($newcomer->id)], []);

        event(new PublicationRosterChanged(FakeCampaignSource::TYPE, self::ID, [$newcomer->id]));

        $this->assertFalse($this->follows()->followsCategory(strtolower($newcomer->id), $space->id()));
        $this->assertSame(0, app(SiteNotificationServiceInterface::class)->total($newcomer));
    }

    public function test_the_system_account_never_follows_a_space(): void
    {
        $systemId = app(SystemAccountInterface::class)->id();
        FakeCampaignSource::$rosters[self::ID] = new PublicationRosterDTO([$systemId], []);

        $this->openSpace();

        $this->assertFalse($this->follows()->followsCategory($systemId, $this->space()->id()));
    }

    public function test_a_roster_that_cannot_be_read_fails_so_the_change_is_tried_again(): void
    {
        $this->openSpace();
        FakeCampaignSource::$rosterFails = true;

        $this->expectException(RuntimeException::class);

        app(SpaceRosterFollows::class)->sync(FakeCampaignSource::TYPE, self::ID, [$this->player->id]);
    }

    private function openSpace(): void
    {
        app(CampaignSpaceSync::class)->sync(new PublicationKey(FakeCampaignSource::TYPE, self::ID));
    }

    private function space(): Category
    {
        $space = app(CategoryRepositoryInterface::class)->findBySubject(new CategorySubject(FakeCampaignSource::TYPE, self::ID));
        $this->assertInstanceOf(Category::class, $space);

        return $space;
    }

    private function follows(): FollowsInterface
    {
        return app(FollowsInterface::class);
    }
}
