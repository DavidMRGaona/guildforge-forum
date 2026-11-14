<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ModerationLogModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Titles given by whoever moderates the whole forum: set, changed and removed, each change in
 * the moderation log; nobody else may, and only active accounts take one.
 */
final class MemberTitleEndpointsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MODERATE = 'forum:forum.moderate';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $moderator;

    private UserModel $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->moderator = $this->anAccount(self::MODERATE);
        $this->person = $this->anAccount();
    }

    public function test_a_forum_moderator_sets_a_title_and_it_is_logged(): void
    {
        $this->actingAs($this->moderator)->postJson(route('forum.titles.store'), ['user' => $this->person->id, 'title' => '  Bardo '])
            ->assertOk()
            ->assertJsonPath('title', 'Bardo');

        $this->assertSame('Bardo', DB::table('forum_member_titles')->where('user_id', $this->person->id)->value('title'));
        $entry = ModerationLogModel::query()->where('action', ModerationAction::TitleChanged->value)->sole();
        $this->assertSame(strtolower($this->person->id), strtolower((string) $entry->subject_user_id));
        $this->assertSame(strtolower($this->moderator->id), strtolower((string) $entry->moderator_id));
        $this->assertSame(['from' => null, 'to' => 'Bardo'], $entry->details);
    }

    public function test_changing_and_removing_are_logged_with_both_values(): void
    {
        $this->setTitle('Bardo')->assertOk();
        $this->setTitle('Juglar')->assertOk();
        $this->setTitle(null)->assertOk()->assertJsonPath('title', null);

        $this->assertSame(0, DB::table('forum_member_titles')->where('user_id', $this->person->id)->count());
        $details = ModerationLogModel::query()->where('action', ModerationAction::TitleChanged->value)
            ->orderBy('created_at')->orderBy('id')->get()->map(static fn (ModerationLogModel $entry): mixed => $entry->details)->all();
        $this->assertSame([
            ['from' => null, 'to' => 'Bardo'],
            ['from' => 'Bardo', 'to' => 'Juglar'],
            ['from' => 'Juglar', 'to' => null],
        ], $details);
    }

    public function test_the_same_title_writes_nothing(): void
    {
        $this->setTitle('Bardo')->assertOk();
        $this->setTitle(' Bardo')->assertOk();
        $this->setTitle('')->assertOk();
        $this->setTitle(null)->assertOk();

        $this->assertSame(2, ModerationLogModel::query()->where('action', ModerationAction::TitleChanged->value)->count());
    }

    public function test_a_category_moderator_cannot(): void
    {
        $role = RoleModel::query()->create(['name' => 'tablemasters', 'display_name' => 'Tablemasters']);
        app(CategoryRepositoryInterface::class)->save(new Category(
            CategoryId::generate(),
            'Rol',
            new CategorySlug('rol'),
            null,
            1,
            viewAudience: CategoryAudience::everyone(),
            writeAudience: CategoryAudience::signedIn(),
            moderators: new CategoryModerators([$role->id]),
        ));
        $keeper = $this->anAccount();
        $keeper->roles()->attach($role->id);

        $this->actingAs($keeper)->postJson(route('forum.titles.store'), ['user' => $this->person->id, 'title' => 'Bardo'])
            ->assertForbidden();
        $this->assertSame(0, DB::table('forum_member_titles')->count());
    }

    public function test_a_member_and_a_guest_cannot(): void
    {
        $this->postJson(route('forum.titles.store'), ['user' => $this->person->id, 'title' => 'Bardo'])->assertUnauthorized();
        $this->actingAs($this->anAccount())->postJson(route('forum.titles.store'), ['user' => $this->person->id, 'title' => 'Bardo'])
            ->assertForbidden();
    }

    public function test_an_inactive_account_takes_no_title(): void
    {
        $deactivated = $this->anAccount();
        $deactivated->delete();
        $anonymized = $this->anAccount();
        $anonymized->forceFill(['anonymized_at' => CarbonImmutable::now()])->save();
        $system = app(SystemAccountInterface::class)->id();

        foreach ([$deactivated->id, $anonymized->id, $system] as $user) {
            $this->actingAs($this->moderator)->postJson(route('forum.titles.store'), ['user' => $user, 'title' => 'Bardo'])
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'not_titleable');
        }
        $this->assertSame(0, DB::table('forum_member_titles')->count());
    }

    public function test_an_invalid_title_is_refused(): void
    {
        $this->setTitle(str_repeat('a', 31))->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->setTitle("Lanzador\nde dados")->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertSame(0, DB::table('forum_member_titles')->count());
    }

    public function test_a_moderator_may_title_themselves(): void
    {
        $this->actingAs($this->moderator)->postJson(route('forum.titles.store'), ['user' => $this->moderator->id, 'title' => 'Guardián'])
            ->assertOk();
    }

    private function setTitle(?string $title): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->moderator)->postJson(route('forum.titles.store'), ['user' => $this->person->id, 'title' => $title]);
    }
}
