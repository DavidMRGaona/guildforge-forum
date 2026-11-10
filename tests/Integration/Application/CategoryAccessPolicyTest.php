<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Publications\DTOs\PublicationRosterDTO;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Application\Services\EmailVerificationPolicyInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\RosterScope;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Tests\Support\ExampleCampaignSource;
use Tests\Support\Modules\ModuleTestCase;

/**
 * CategoryAccessPolicy decides alone who reads, writes in and moderates each category, and fails
 * closed.
 */
final class CategoryAccessPolicyTest extends ModuleTestCase
{
    private const string CAMPAIGN = 'c-1';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        ExampleCampaignSource::reset();
        app(PublicationSourceRegistryInterface::class)->register(ExampleCampaignSource::class, 'example');
    }

    protected function tearDown(): void
    {
        ExampleCampaignSource::reset();

        parent::tearDown();
    }

    public function test_a_public_category_is_read_by_guests_and_written_in_by_accounts(): void
    {
        $category = $this->category(CategoryAudience::everyone(), CategoryAudience::signedIn());

        $this->assertTrue($this->policy()->canView(null, $category));
        $this->assertFalse($this->policy()->canWrite(null, $category));
        $this->assertTrue($this->policy()->canWrite($this->user(), $category));
        $this->assertFalse($this->policy()->canModerate($this->user(), $category));
    }

    public function test_a_category_for_accounts_is_closed_to_guests(): void
    {
        $category = $this->category(CategoryAudience::signedIn(), CategoryAudience::signedIn());

        $this->assertFalse($this->policy()->canView(null, $category));
        $this->assertTrue($this->policy()->canView($this->user(), $category));
    }

    public function test_association_members_are_asked_to_the_membership_service(): void
    {
        $category = $this->category(CategoryAudience::associationMembers(), CategoryAudience::associationMembers());
        $member = $this->user(['socio']);

        $this->assertTrue($this->policy()->canView($member, $category));
        $this->assertTrue($this->policy()->canWrite($member, $category));
        $this->assertFalse($this->policy()->canView($this->user(), $category));
    }

    public function test_a_roles_rule_lets_in_holders_of_any_of_its_roles(): void
    {
        $board = $this->role('board');
        $category = $this->category(CategoryAudience::roles([$board->id]), CategoryAudience::roles([$board->id]));

        $this->assertTrue($this->policy()->canView($this->user(['board']), $category));
        $this->assertFalse($this->policy()->canView($this->user(), $category));
    }

    public function test_a_renamed_role_keeps_its_rules(): void
    {
        $board = $this->role('board');
        $category = $this->category(CategoryAudience::roles([$board->id]), CategoryAudience::signedIn());
        $holder = $this->user(['board']);

        $board->update(['name' => 'junta']);
        app(AuthorizationServiceInterface::class)->invalidateCache();

        $this->assertTrue($this->freshPolicy()->canView($holder, $category));
    }

    public function test_a_deleted_role_lets_nobody_in(): void
    {
        $board = $this->role('board');
        $category = $this->category(CategoryAudience::roles([$board->id]), CategoryAudience::signedIn());
        $holder = $this->user(['board']);

        $board->delete();
        app(AuthorizationServiceInterface::class)->invalidateCache();

        $this->assertFalse($this->freshPolicy()->canView($holder, $category));
    }

    public function test_an_inactive_role_does_not_count(): void
    {
        // A role that requires membership is inactive while its holder is not a socio
        $board = $this->role('board', requiresMembership: true);
        $category = $this->category(CategoryAudience::roles([$board->id]), CategoryAudience::signedIn());

        $this->assertFalse($this->policy()->canView($this->user(['board']), $category));
        $this->assertTrue($this->policy()->canView($this->user(['board', 'socio']), $category));
    }

    public function test_the_roster_rule_lets_in_players_and_directors_and_the_leaders_rule_only_directors(): void
    {
        $player = $this->user();
        $director = $this->user();
        ExampleCampaignSource::$rosters[self::CAMPAIGN] = new PublicationRosterDTO([$player->id], [$director->id]);

        $space = $this->category(CategoryAudience::roster(), CategoryAudience::rosterLeaders(), subject: true);

        $this->assertTrue($this->policy()->canView($player, $space));
        $this->assertTrue($this->policy()->canView($director, $space));
        $this->assertFalse($this->policy()->canView($this->user(), $space));
        $this->assertFalse($this->policy()->canWrite($player, $space));
        $this->assertTrue($this->policy()->canWrite($director, $space));
    }

    public function test_a_missing_or_failing_roster_lets_nobody_in(): void
    {
        $player = $this->user();
        $space = $this->category(CategoryAudience::roster(), CategoryAudience::roster(), subject: true);

        $this->assertFalse($this->policy()->canView($player, $space));

        ExampleCampaignSource::$rosters[self::CAMPAIGN] = new PublicationRosterDTO([$player->id]);
        ExampleCampaignSource::$fails = true;
        $this->assertFalse($this->freshPolicy()->canView($player, $space));
    }

    public function test_a_roster_of_a_source_that_is_gone_lets_nobody_in(): void
    {
        $player = $this->user();
        ExampleCampaignSource::$rosters[self::CAMPAIGN] = new PublicationRosterDTO([$player->id]);
        $space = $this->category(CategoryAudience::roster(), CategoryAudience::roster(), subject: true);

        app(PublicationSourceRegistryInterface::class)->unregisterModule('example');

        $this->assertFalse($this->freshPolicy()->canView($player, $space));
    }

    public function test_a_roster_is_read_once_per_request(): void
    {
        $player = $this->user();
        ExampleCampaignSource::$rosters[self::CAMPAIGN] = new PublicationRosterDTO([$player->id]);
        $space = $this->category(CategoryAudience::roster(), CategoryAudience::roster(), subject: true);

        $this->policy()->canView($player, $space);
        $this->policy()->canWrite($player, $space);

        $this->assertSame(1, ExampleCampaignSource::$lookups);
    }

    public function test_reading_a_subcategory_needs_reading_its_parent(): void
    {
        $parent = $this->category(CategoryAudience::associationMembers(), CategoryAudience::associationMembers());
        $child = $this->category(CategoryAudience::signedIn(), CategoryAudience::signedIn(), parent: $parent, slug: 'hija');

        $this->assertFalse($this->policy()->canView($this->user(), $child));
        $this->assertFalse($this->policy()->canWrite($this->user(), $child));
        $this->assertTrue($this->policy()->canView($this->user(['socio']), $child));
    }

    public function test_a_subcategory_whose_parent_is_unreadable_or_gone_shows_to_nobody(): void
    {
        $parent = $this->category(CategoryAudience::everyone(), CategoryAudience::signedIn());
        $child = $this->category(CategoryAudience::everyone(), CategoryAudience::signedIn(), parent: $parent, slug: 'hija');

        DB::table('forum_categories')->where('id', $parent->id()->value)->update(['view_rule' => '{"kind":"nadie"}']);
        $this->assertFalse($this->freshPolicy()->canView(null, $child));

        DB::table('forum_categories')->where('id', $child->id()->value)->update(['parent_id' => null]);
        DB::table('forum_categories')->where('id', $parent->id()->value)->delete();
        $this->assertFalse($this->freshPolicy()->canView(null, $child));
    }

    public function test_writing_needs_the_email_verified_when_the_site_asks_for_it(): void
    {
        $category = $this->category(CategoryAudience::everyone(), CategoryAudience::signedIn());
        $this->app->instance(EmailVerificationPolicyInterface::class, new class implements EmailVerificationPolicyInterface
        {
            public function mustVerify(object $user): bool
            {
                return true;
            }
        });

        $this->assertFalse($this->freshPolicy()->canWrite($this->user(), $category));
        $this->assertTrue($this->freshPolicy()->canView($this->user(), $category));
    }

    public function test_the_forum_moderators_read_write_and_moderate_every_category(): void
    {
        $moderator = $this->userWithPermission(CategoryAccessPolicy::MODERATE_FORUM);
        $space = $this->category(CategoryAudience::roster(), CategoryAudience::roster(), subject: true);

        $this->assertTrue($this->policy()->canModerateForum($moderator));
        $this->assertTrue($this->policy()->canView($moderator, $space));
        $this->assertTrue($this->policy()->canWrite($moderator, $space));
        $this->assertTrue($this->policy()->canModerate($moderator, $space));
        $this->assertFalse($this->policy()->canModerateForum($this->user()));
    }

    public function test_category_moderators_moderate_and_write_where_they_read(): void
    {
        $mods = $this->role('mods');
        $category = $this->category(CategoryAudience::signedIn(), CategoryAudience::associationMembers(), new CategoryModerators([$mods->id]));
        $moderator = $this->user(['mods']);

        $this->assertTrue($this->policy()->canModerate($moderator, $category));
        // Not a socio, but moderators write where they moderate
        $this->assertTrue($this->policy()->canWrite($moderator, $category));
        $this->assertFalse($this->policy()->canWrite($this->user(), $category));
    }

    public function test_a_category_moderator_who_cannot_read_it_does_not_moderate_it(): void
    {
        $mods = $this->role('mods');
        $category = $this->category(CategoryAudience::associationMembers(), CategoryAudience::associationMembers(), new CategoryModerators([$mods->id]));

        $this->assertFalse($this->policy()->canModerate($this->user(['mods']), $category));
    }

    public function test_the_parent_moderators_moderate_its_subcategories(): void
    {
        $mods = $this->role('mods');
        $parent = $this->category(CategoryAudience::everyone(), CategoryAudience::signedIn(), new CategoryModerators([$mods->id]));
        $child = $this->category(CategoryAudience::everyone(), CategoryAudience::signedIn(), parent: $parent, slug: 'hija');

        $this->assertTrue($this->policy()->canModerate($this->user(['mods']), $child));
    }

    public function test_the_directors_moderate_their_campaign_space(): void
    {
        $player = $this->user();
        $director = $this->user();
        ExampleCampaignSource::$rosters[self::CAMPAIGN] = new PublicationRosterDTO([$player->id], [$director->id]);
        $space = $this->category(CategoryAudience::roster(), CategoryAudience::roster(), new CategoryModerators([], RosterScope::Leaders), subject: true);

        $this->assertTrue($this->policy()->canModerate($director, $space));
        $this->assertFalse($this->policy()->canModerate($player, $space));
        $this->assertFalse($this->policy()->canModerate(null, $space));
    }

    private function category(
        CategoryAudience $view,
        CategoryAudience $write,
        ?CategoryModerators $moderators = null,
        ?Category $parent = null,
        bool $subject = false,
        string $slug = 'general',
    ): Category {
        $category = new Category(
            CategoryId::generate(),
            ucfirst($slug),
            new CategorySlug($slug),
            parentId: $parent?->id(),
            viewAudience: $view,
            writeAudience: $write,
            moderators: $moderators,
            subject: $subject ? new CategorySubject(ExampleCampaignSource::TYPE, self::CAMPAIGN) : null,
        );
        app(CategoryRepositoryInterface::class)->save($category);

        return $category;
    }

    private function role(string $name, bool $requiresMembership = false): RoleModel
    {
        return RoleModel::query()->create(['name' => $name, 'display_name' => ucfirst($name), 'requires_association_member' => $requiresMembership]);
    }

    /**
     * @param  list<string>  $roles
     */
    private function user(array $roles = []): UserModel
    {
        $user = UserModel::factory()->create();
        foreach ($roles as $name) {
            $role = RoleModel::query()->where('name', $name)->first() ?? $this->role($name);
            $user->roles()->attach($role->id);
        }
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($user);

        return $user;
    }

    private function userWithPermission(string $key): UserModel
    {
        $role = $this->role('tester-'.strtolower(uniqid()));
        $permission = PermissionModel::query()->firstOrCreate(['key' => $key], ['label' => $key, 'resource' => 'forum', 'action' => 'moderate', 'module' => 'forum']);
        $role->permissions()->attach($permission->id);

        return $this->user([$role->name]);
    }

    private function policy(): CategoryAccessPolicy
    {
        return app(CategoryAccessPolicy::class);
    }

    /**
     * As a new request: nothing remembered from earlier answers.
     */
    private function freshPolicy(): CategoryAccessPolicy
    {
        $this->app->forgetScopedInstances();

        return app(CategoryAccessPolicy::class);
    }
}
