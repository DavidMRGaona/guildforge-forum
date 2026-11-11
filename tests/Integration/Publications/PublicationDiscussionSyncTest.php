<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Publications;

use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\DTOs\PublicationTopicDTO;
use App\Application\Publications\Enums\PublicationState;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Application\Services\SettingsServiceInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\MentionCandidatesInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Publications\PublicationDiscussionSync;
use Modules\Forum\Application\Services\DefaultCategoriesInstaller;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Infrastructure\Jobs\SyncPublicationDiscussionJob;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostRevisionModel;
use Modules\Forum\Infrastructure\Services\EloquentSystemAccount;
use Modules\Forum\Tests\Support\FakeGameTableSource;
use Modules\Forum\Tests\Support\FakePublicationSource;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Each publication gets one discussion, and each change worth telling is said once, whatever
 * the number of times it is saved or checked.
 */
final class PublicationDiscussionSyncTest extends ModuleTestCase
{
    use UsesUtcStorage;

    private const string ID = '0b1f8a52-5c43-4c1b-9f43-7a3a8f0a1c11';

    private const string SYSTEM_TOPIC = 'game-system.4e0c2c1a-0b7e-4a59-8c55-0f3b2d1e9a77';

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

    public function test_a_published_table_gets_a_discussion_in_its_category_written_by_the_system_account(): void
    {
        $this->publish();
        $this->sync();

        $discussion = $this->discussion();
        $category = app(CategoryRepositoryInterface::class)->findBySystemKey(SystemCategory::GameTables);
        $this->assertNotNull($category);
        $this->assertTrue($discussion->categoryId()->equals($category->id()));
        $this->assertSame('Mesa de Dune', $discussion->title()->value);
        $this->assertSame(app(SystemAccountInterface::class)->id(), $discussion->authorId());
        $this->assertSame(DiscussionStatus::Open, $discussion->status());

        $date = app(TimezoneServiceInterface::class)->format(new DateTimeImmutable('2026-12-01 18:00:00 UTC'), 'd/m/Y H:i');
        $this->assertSame(["Partida de rol en el desierto.\nFecha: {$date}.\nSistema: Dune.\nVer la ficha"], $this->texts());
        $this->assertStringContainsString('https://example.test/mesas/dune', (string) json_encode(PostModel::query()->firstOrFail()->body, JSON_UNESCAPED_SLASHES));

        $topics = app(DiscussionRepositoryInterface::class)->topicsOf($discussion->id());
        $this->assertSame([self::SYSTEM_TOPIC], array_map(static fn ($topic): string => $topic->key, $topics));
    }

    public function test_checking_again_never_opens_another_discussion_nor_repeats_anything(): void
    {
        $this->publish(isFull: true, registrationOpen: true);
        $this->sync();
        $this->sync();
        $this->sync();

        $this->assertSame(1, DiscussionModel::query()->count());
        $this->assertCount(1, $this->texts());
    }

    public function test_a_publication_cancelled_or_over_before_it_had_a_discussion_never_gets_one(): void
    {
        $this->publish(state: PublicationState::Cancelled);
        $this->sync();
        $this->publish(state: PublicationState::Ended);
        $this->sync();

        $this->assertSame(0, DiscussionModel::query()->count());

        $this->publish();
        $this->sync();

        $this->assertSame(1, DiscussionModel::query()->count());
    }

    public function test_a_new_date_is_told_once(): void
    {
        $this->publish();
        $this->sync();

        $this->publish(startsAt: '2026-12-08 18:00:00 UTC');
        $this->sync();
        $this->sync();

        $date = app(TimezoneServiceInterface::class)->format(new DateTimeImmutable('2026-12-08 18:00:00 UTC'), 'd/m/Y H:i');
        $this->assertSame("Nueva fecha: {$date}.\nVer la ficha", $this->texts()[1]);
        $this->assertCount(2, $this->texts());
    }

    public function test_a_place_freed_in_a_full_table_is_told_only_while_registrations_are_open(): void
    {
        $this->publish(isFull: true, registrationOpen: true);
        $this->sync();

        $this->publish(isFull: false, registrationOpen: true);
        $this->sync();
        $this->sync();

        $this->assertSame("Ha quedado una plaza libre: ya te puedes inscribir.\nVer la ficha", $this->texts()[1]);

        $this->publish(isFull: true, registrationOpen: true);
        $this->sync();
        $this->publish(isFull: false, registrationOpen: false);
        $this->sync();

        $this->assertSame(['Se han cerrado las inscripciones.'], array_slice($this->texts(), 2));
    }

    public function test_registrations_closing_are_told_and_checked_again_at_their_time(): void
    {
        Bus::fake([SyncPublicationDiscussionJob::class]);

        $this->publish(registrationOpen: true, closesAt: '2026-11-25 20:00:00 UTC');
        $this->sync();
        $this->sync();

        Bus::assertDispatchedTimes(SyncPublicationDiscussionJob::class, 1);
        Bus::assertDispatched(SyncPublicationDiscussionJob::class, static fn (SyncPublicationDiscussionJob $job): bool => $job->type === FakeGameTableSource::TYPE
            && $job->id === self::ID
            && $job->delay instanceof DateTimeImmutable
            && $job->delay->getTimestamp() === (new DateTimeImmutable('2026-11-25 20:00:30 UTC'))->getTimestamp());

        $this->travelTo(new DateTimeImmutable('2026-11-25 20:00:30 UTC'));
        $this->publish(registrationOpen: false, closesAt: '2026-11-25 20:00:00 UTC');
        $this->sync();

        $this->assertSame('Se han cerrado las inscripciones.', $this->texts()[1]);
    }

    public function test_cancelling_closes_the_discussion_and_reactivating_opens_it_again(): void
    {
        $this->publish();
        $this->sync();

        $this->publish(state: PublicationState::Cancelled);
        $this->sync();
        $this->sync();

        $this->assertSame(DiscussionStatus::Closed, $this->discussion()->status());
        $this->assertSame(['Se ha cancelado. El tema queda cerrado.'], array_slice($this->texts(), 1));

        $this->publish();
        $this->sync();

        $this->assertSame(DiscussionStatus::Open, $this->discussion()->status());
        $this->assertSame("Se ha reactivado: vuelve a estar en marcha.\nVer la ficha", $this->texts()[2]);
    }

    public function test_a_discussion_a_moderator_closed_is_never_opened_by_the_forum(): void
    {
        $this->publish();
        $this->sync();
        $this->moderatorCloses();

        $this->publish(state: PublicationState::Cancelled);
        $this->sync();
        $this->publish();
        $this->sync();

        $this->assertSame(DiscussionStatus::Closed, $this->discussion()->status());
        $this->assertCount(3, $this->texts());
    }

    public function test_a_discussion_a_moderator_reopened_is_left_open(): void
    {
        $this->publish();
        $this->sync();
        $this->publish(state: PublicationState::Cancelled);
        $this->sync();

        $discussion = $this->discussion();
        $discussion->reopen();
        app(ForumWriter::class)->saveDiscussion($discussion);

        $this->publish();
        $this->sync();
        $this->publish(state: PublicationState::Cancelled);
        $this->sync();

        // Closed again by the forum this time, so publishing it again opens it
        $this->assertSame(DiscussionStatus::Closed, $this->discussion()->status());
        $this->publish();
        $this->sync();
        $this->assertSame(DiscussionStatus::Open, $this->discussion()->status());
    }

    public function test_taking_it_down_closes_the_discussion_without_deleting_it_and_publishing_again_opens_it(): void
    {
        $this->publish();
        $this->sync();

        FakePublicationSource::forget(FakeGameTableSource::TYPE, self::ID);
        $this->sync();
        $this->sync();

        $this->assertSame(DiscussionStatus::Closed, $this->discussion()->status());
        $this->assertSame(['Se ha retirado de la web. El tema queda cerrado.'], array_slice($this->texts(), 1));
        $this->assertFalse(app(SubjectDiscussionRepositoryInterface::class)->find($this->key())?->isLive());

        $this->publish(startsAt: '2026-12-15 18:00:00 UTC');
        $this->sync();

        $this->assertSame(DiscussionStatus::Open, $this->discussion()->status());
        $this->assertSame(1, DiscussionModel::query()->count());
        $this->assertStringStartsWith('Se ha vuelto a publicar en la web.', $this->texts()[2]);
        $this->assertStringStartsWith('Nueva fecha:', $this->texts()[3]);
    }

    public function test_a_deleted_publication_is_told_as_deleted(): void
    {
        $this->publish();
        $this->sync();

        FakePublicationSource::forget(FakeGameTableSource::TYPE, self::ID);
        $this->sync(removed: true);

        $this->assertSame('Se ha borrado de la web. El tema queda cerrado.', $this->texts()[1]);
        $this->assertSame(DiscussionStatus::Closed, $this->discussion()->status());
    }

    public function test_a_failing_source_fails_the_check_and_is_never_taken_for_a_publication_taken_down(): void
    {
        $this->publish();
        $this->sync();

        FakePublicationSource::$failing[FakeGameTableSource::TYPE] = true;

        try {
            $this->sync();
            $this->fail('The check should fail so the job is retried.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Publication source unavailable', $exception->getMessage());
        }

        $this->assertCount(1, $this->texts());
        $this->assertSame(DiscussionStatus::Open, $this->discussion()->status());
    }

    public function test_a_type_without_a_source_leaves_its_discussion_as_it_is(): void
    {
        $this->publish();
        $this->sync();

        app(PublicationSourceRegistryInterface::class)->unregisterModule('example');
        $this->sync();

        $this->assertCount(1, $this->texts());
        $this->assertSame(DiscussionStatus::Open, $this->discussion()->status());
    }

    public function test_a_new_title_renames_the_discussion_and_rewrites_its_first_post(): void
    {
        $this->publish();
        $this->sync();

        $this->publish(title: 'Mesa de Arrakis', summary: 'Partida en Arrakis.');
        $this->sync();

        $this->assertSame('Mesa de Arrakis', $this->discussion()->title()->value);
        $this->assertStringStartsWith('Partida en Arrakis.', $this->texts()[0]);
        $this->assertCount(1, $this->texts());
        $this->assertSame(1, PostRevisionModel::query()->count());
    }

    public function test_a_discussion_a_moderator_deleted_is_never_opened_again(): void
    {
        $this->publish();
        $this->sync();
        app(ForumWriter::class)->deleteDiscussion($this->discussion()->id());

        $this->publish(startsAt: '2026-12-08 18:00:00 UTC');
        $this->sync();

        $this->assertSame(0, DiscussionModel::query()->count());
    }

    public function test_the_system_account_holds_no_role_and_carries_the_site_name(): void
    {
        $this->publish();
        $this->sync();

        $account = UserModel::query()->findOrFail(app(SystemAccountInterface::class)->id());
        $this->assertSame([], $account->roles()->pluck('name')->all());
        $this->assertNull($account->email_verified_at);
        $this->assertStringEndsWith('@system.invalid', $account->email);
        $this->assertSame(1, UserModel::query()->count());
        $this->assertTrue(app(SystemAccountInterface::class)->isSystemAccount(strtoupper($account->id)));

        app(SettingsServiceInterface::class)->set('guild_name', 'Club de ejemplo');
        $this->assertSame($account->id, app(SystemAccountInterface::class)->id());
        $this->assertSame('Club de ejemplo', $account->fresh()?->name);
    }

    public function test_an_account_deleted_or_anonymized_from_the_panel_is_replaced_on_next_use(): void
    {
        $first = app(SystemAccountInterface::class)->id();
        UserModel::query()->findOrFail($first)->delete();

        $second = app(SystemAccountInterface::class)->id();
        $this->assertNotSame($first, $second);
        $this->assertSame($second, app(SettingsServiceInterface::class)->get(EloquentSystemAccount::SETTING));

        UserModel::query()->findOrFail($second)->forceFill(['anonymized_at' => now()])->save();

        $third = app(SystemAccountInterface::class)->id();
        $this->assertNotSame($second, $third);
        $this->assertFalse(app(SystemAccountInterface::class)->isSystemAccount($second));
    }

    public function test_an_account_somebody_registered_is_never_taken_for_the_system_account(): void
    {
        $someone = UserModel::factory()->create(['email' => 'forum-system@system.invalid']);

        $this->assertNotSame($someone->id, app(SystemAccountInterface::class)->id());
        $this->assertFalse(app(SystemAccountInterface::class)->isSystemAccount($someone->id));
    }

    public function test_the_system_account_is_never_mentionable(): void
    {
        $system = app(SystemAccountInterface::class)->id();
        UserModel::query()->findOrFail($system)->forceFill(['name' => 'Asociación', 'display_name' => 'Asociación'])->saveQuietly();
        $someone = UserModel::factory()->create(['name' => 'Asunción', 'display_name' => null]);

        $this->assertSame([strtolower($someone->id)], app(AccountDirectoryInterface::class)->mentionable([$system, $someone->id]));

        $this->publish();
        $this->sync();
        $names = array_map(static fn ($candidate): string => $candidate->id, app(MentionCandidatesInterface::class)->search($this->discussion(), 'As', 10));
        $this->assertNotContains($system, $names);
        $this->assertContains(strtolower($someone->id), $names);
    }

    public function test_a_moderator_who_reopens_and_closes_again_keeps_it_closed(): void
    {
        $this->publish();
        $this->sync();
        $this->publish(state: PublicationState::Cancelled);
        $this->sync();

        $discussion = $this->discussion();
        $discussion->reopen();
        app(ForumWriter::class)->saveDiscussion($discussion);
        $this->moderatorCloses();

        $this->publish();
        $this->sync();

        $this->assertSame(DiscussionStatus::Closed, $this->discussion()->status());
    }

    public function test_uninstalling_with_data_forgets_the_system_account_setting(): void
    {
        app(SystemAccountInterface::class)->id();
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_13_000001_create_forum_subject_discussions.php';

        $migration->down();
        $this->assertSame(0, DB::table('settings')->where('key', EloquentSystemAccount::SETTING)->count());

        $migration->up();
    }

    public function test_notices_are_visible_posts_with_the_next_numbers(): void
    {
        $this->publish();
        $this->sync();
        $this->publish(state: PublicationState::Cancelled);
        $this->sync();

        $this->assertSame([1, 2], PostModel::query()->orderBy('number')->pluck('number')->all());
        $this->assertSame([PostStatus::Visible->value], PostModel::query()->distinct()->pluck('status')->all());
    }

    private function publish(
        PublicationState $state = PublicationState::Active,
        string $startsAt = '2026-12-01 18:00:00 UTC',
        bool $isFull = false,
        bool $registrationOpen = false,
        ?string $closesAt = null,
        string $title = 'Mesa de Dune',
        string $summary = 'Partida de rol en el desierto.',
    ): void {
        FakePublicationSource::put(new PublicationDTO(
            type: FakeGameTableSource::TYPE,
            id: self::ID,
            title: $title,
            summary: $summary,
            url: 'https://example.test/mesas/dune',
            state: $state,
            startsAt: new DateTimeImmutable($startsAt),
            endsAt: (new DateTimeImmutable($startsAt))->modify('+4 hours'),
            topics: [new PublicationTopicDTO(self::SYSTEM_TOPIC, 'Dune')],
            isFull: $isFull,
            registrationOpen: $registrationOpen,
            registrationClosesAt: $closesAt === null ? null : new DateTimeImmutable($closesAt),
        ));
    }

    private function sync(bool $removed = false): void
    {
        app(PublicationDiscussionSync::class)->sync($this->key(), $removed);
    }

    private function key(): PublicationKey
    {
        return new PublicationKey(FakeGameTableSource::TYPE, self::ID);
    }

    private function discussion(): Discussion
    {
        $id = app(SubjectDiscussionRepositoryInterface::class)->find($this->key())?->discussionId();
        $this->assertInstanceOf(DiscussionId::class, $id);

        return app(DiscussionRepositoryInterface::class)->findOrFail($id);
    }

    private function moderatorCloses(): void
    {
        $discussion = $this->discussion();
        $discussion->close();
        app(ForumWriter::class)->saveDiscussion($discussion);
    }

    /**
     * @return list<string>
     */
    private function texts(): array
    {
        /** @var list<string> */
        return PostModel::query()->orderBy('number')->pluck('plain_text')->all();
    }
}
