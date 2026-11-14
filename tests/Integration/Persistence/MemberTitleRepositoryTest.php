<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Persistence;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Domain\Repositories\MemberTitleRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\MemberTitle;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Titles kept per person, read only for accounts that are still active.
 */
final class MemberTitleRepositoryTest extends ModuleTestCase
{
    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moderator = UserModel::factory()->create();
    }

    public function test_a_title_is_saved_and_read(): void
    {
        $person = UserModel::factory()->create();
        $this->titles()->save($person->id, $this->title('Bardo'), $this->moderator->id, new DateTimeImmutable('2026-11-14 12:00:00'));

        $this->assertSame('Bardo', $this->titles()->find($person->id)?->value);
        $this->assertSame([strtolower($person->id) => 'Bardo'], $this->titles()->titlesOf([$person->id]));
        $this->assertSame($this->moderator->id, DB::table('forum_member_titles')->where('user_id', $person->id)->value('moderator_id'));
    }

    public function test_saving_again_keeps_the_last_title(): void
    {
        $person = UserModel::factory()->create();
        $this->titles()->save($person->id, $this->title('Bardo'), $this->moderator->id, new DateTimeImmutable('2026-11-14 12:00:00'));
        $this->titles()->save($person->id, $this->title('Juglar'), $this->moderator->id, new DateTimeImmutable('2026-11-14 13:00:00'));

        $this->assertSame('Juglar', $this->titles()->find($person->id)?->value);
        $this->assertSame(1, DB::table('forum_member_titles')->where('user_id', $person->id)->count());
    }

    public function test_forgetting_removes_it(): void
    {
        $person = UserModel::factory()->create();
        $this->titles()->save($person->id, $this->title('Bardo'), $this->moderator->id, new DateTimeImmutable('2026-11-14 12:00:00'));
        $this->titles()->forget($person->id);

        $this->assertNull($this->titles()->find($person->id));
    }

    public function test_titles_are_read_only_for_active_accounts(): void
    {
        $active = UserModel::factory()->create();
        $deactivated = UserModel::factory()->create();
        $anonymized = UserModel::factory()->create();
        foreach ([$active, $deactivated, $anonymized] as $person) {
            $this->titles()->save($person->id, $this->title('Bardo'), $this->moderator->id, new DateTimeImmutable('2026-11-14 12:00:00'));
        }
        $deactivated->delete();
        $anonymized->forceFill(['anonymized_at' => CarbonImmutable::now()])->save();

        $this->assertSame(
            [strtolower($active->id) => 'Bardo'],
            $this->titles()->titlesOf([$active->id, $deactivated->id, $anonymized->id]),
        );
    }

    public function test_malformed_ids_are_ignored(): void
    {
        $this->assertSame([], $this->titles()->titlesOf(['no-uuid', '']));
    }

    public function test_the_titles_of_many_people_take_one_query(): void
    {
        $people = UserModel::factory()->count(3)->create();
        foreach ($people as $person) {
            $this->titles()->save($person->id, $this->title('Bardo'), $this->moderator->id, new DateTimeImmutable('2026-11-14 12:00:00'));
        }

        DB::enableQueryLog();
        $this->titles()->titlesOf($people->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    private function title(string $text): MemberTitle
    {
        $title = MemberTitle::fromInput($text);
        $this->assertInstanceOf(MemberTitle::class, $title);

        return $title;
    }

    private function titles(): MemberTitleRepositoryInterface
    {
        return app(MemberTitleRepositoryInterface::class);
    }
}
