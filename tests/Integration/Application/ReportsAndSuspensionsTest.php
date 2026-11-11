<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumSuspensions;
use Modules\Forum\Application\Services\PostLikeService;
use Modules\Forum\Application\Services\ReportService;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Events\ForumPostApproved;
use Modules\Forum\Domain\Events\ForumPostReported;
use Modules\Forum\Domain\Events\ForumUserSuspended;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\ReportRepositoryInterface;
use Modules\Forum\Domain\Repositories\SuspensionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ModerationLogModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ReportModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

final class ReportsAndSuspensionsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MODERATE = 'forum:forum.moderate';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private UserModel $author;

    private UserModel $moderator;

    private Discussion $discussion;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->author = $this->anAccount();
        $this->moderator = $this->anAccount(self::MODERATE);
        [$this->discussion, $this->post] = $this->aDiscussion($this->general, authorId: strtolower($this->author->id));
    }

    public function test_a_reader_reports_a_post_once_while_their_report_is_open(): void
    {
        Event::fake([ForumPostReported::class]);
        $reader = $this->anAccount();

        $first = $this->reports()->report($reader, $this->post->id(), ReportReason::Offensive, 'Insulta a otra persona');
        $again = $this->reports()->report($reader, $this->post->id(), ReportReason::Spam, null);

        $this->assertTrue($first->id()->equals($again->id()));
        $this->assertSame(1, ReportModel::query()->count());
        Event::assertDispatchedTimes(ForumPostReported::class, 1);
        Event::assertDispatched(ForumPostReported::class, fn (ForumPostReported $event): bool => $event->postId === $this->post->id()->value && $event->reason === 'offensive');
    }

    public function test_nobody_reports_their_own_post_nor_while_suspended(): void
    {
        try {
            $this->reports()->report($this->author, $this->post->id(), ReportReason::Spam, null);
            $this->fail('An author reported their own post.');
        } catch (ModerationRefusedException $exception) {
            $this->assertSame('not_reportable', $exception->reason);
        }

        $reader = $this->anAccount();
        $this->suspensions()->suspend($this->moderator, $reader->id, 'Spam', null);

        $this->expectException(ModerationRefusedException::class);
        $this->reports()->report($reader, $this->post->id(), ReportReason::Spam, null);
    }

    public function test_a_hidden_post_is_not_found_for_reporters(): void
    {
        app(ForumModeration::class)->hidePost($this->moderator, $this->post->id());

        $this->expectException(PostNotFoundException::class);

        $this->reports()->report($this->anAccount(), $this->post->id(), ReportReason::Spam, null);
    }

    public function test_settling_by_hiding_hides_the_post_and_closes_every_open_report(): void
    {
        $this->reports()->report($this->anAccount(), $this->post->id(), ReportReason::Spam, null);
        $this->reports()->report($this->anAccount(), $this->post->id(), ReportReason::Offensive, null);

        $settled = $this->reports()->resolve($this->moderator, $this->post->id(), ReportResolution::Hidden, 'Insultos');

        $this->assertSame(2, $settled);
        $post = app(PostRepositoryInterface::class)->findOrFail($this->post->id());
        $this->assertSame(PostStatus::Hidden, $post->status());
        $this->assertSame('Insultos', $post->hiddenReason());
        $this->assertSame([], app(ReportRepositoryInterface::class)->openOf($this->post->id()));
        $this->assertSame(
            [ModerationAction::PostHidden->value, ModerationAction::ReportResolved->value],
            ModerationLogModel::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_only_its_moderators_settle_reports(): void
    {
        $this->reports()->report($this->anAccount(), $this->post->id(), ReportReason::Spam, null);

        $this->expectException(ForumActionDeniedException::class);

        $this->reports()->resolve($this->anAccount(), $this->post->id(), ReportResolution::NoAction);
    }

    public function test_a_suspended_person_reads_but_does_not_write(): void
    {
        Event::fake([ForumUserSuspended::class]);
        $member = $this->anAccount();
        $access = app(ForumAccessInterface::class);
        $this->assertTrue($access->canWrite($member, $this->discussion));

        $suspension = $this->suspensions()->suspend($this->moderator, $member->id, 'Insultos', CarbonImmutable::now()->addDays(7));
        $this->forgetRequestState();

        $this->assertTrue(app(ForumAccessInterface::class)->canRead($member, $this->discussion));
        $this->assertFalse(app(ForumAccessInterface::class)->canWrite($member, $this->discussion));
        Event::assertDispatched(ForumUserSuspended::class, fn (ForumUserSuspended $event): bool => $event->userId === strtolower($member->id));
        $this->assertSame(ModerationAction::UserSuspended->value, ModerationLogModel::query()->sole()->action);

        // It ends by itself
        $this->travelTo(CarbonImmutable::now()->addDays(8));
        $this->forgetRequestState();
        $this->assertTrue(app(ForumAccessInterface::class)->canWrite($member, $this->discussion));
        $this->assertNotNull($suspension->endsAt());
    }

    public function test_a_suspended_category_moderator_moderates_nothing(): void
    {
        $leader = $this->anAccount();
        $role = RoleModel::query()->create(['name' => 'leaders-'.strtolower(uniqid()), 'display_name' => 'Leaders']);
        $leader->roles()->attach($role->id);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($leader);
        $this->general->changeAccess(CategoryAudience::everyone(), CategoryAudience::signedIn(), new CategoryModerators([strtolower((string) $role->id)]));
        app(CategoryRepositoryInterface::class)->save($this->general);
        $this->forgetRequestState();
        $this->assertTrue(app(ForumAccessInterface::class)->canModerate($leader, $this->discussion));

        $this->suspensions()->suspend($this->moderator, $leader->id, 'Abuso de la moderación', null);
        $this->forgetRequestState();

        $this->assertFalse(app(ForumAccessInterface::class)->canModerate($leader, $this->discussion));
        $this->expectException(ForumActionDeniedException::class);
        app(ForumModeration::class)->pin($leader, $this->discussion->id());
    }

    public function test_hiding_or_editing_a_reported_post_settles_its_reports(): void
    {
        $reply = $this->aReply($this->discussion, '2026-11-10 18:30:00');
        $this->reports()->report($this->anAccount(), $this->post->id(), ReportReason::Spam, null);
        $this->reports()->report($this->anAccount(), $reply->id(), ReportReason::Spam, null);

        app(ForumModeration::class)->hidePost($this->moderator, $reply->id(), 'Spam');
        app(ForumModeration::class)->editPost($this->moderator, $this->post->id(), self::aBody('Sin el enlace'));

        $this->assertSame([], app(ReportRepositoryInterface::class)->openOf($reply->id()));
        $this->assertSame([], app(ReportRepositoryInterface::class)->openOf($this->post->id()));
        $this->assertSame('hidden', ReportModel::query()->where('post_id', $reply->id()->value)->value('resolution'));
        $this->assertSame('edited', ReportModel::query()->where('post_id', $this->post->id()->value)->value('resolution'));
    }

    public function test_a_suspended_person_does_not_like(): void
    {
        $member = $this->anAccount();
        $this->suspensions()->suspend($this->moderator, $member->id, 'Insultos', null);
        $this->forgetRequestState();

        $this->assertFalse(app(PostLikeService::class)->canLike($member, $this->post, $this->discussion));
        $this->expectException(ForumActionDeniedException::class);
        app(PostLikeService::class)->like($member, $this->post->id());
    }

    public function test_lifting_lets_them_write_again(): void
    {
        $member = $this->anAccount();
        $suspension = $this->suspensions()->suspend($this->moderator, $member->id, 'Insultos', null);

        $this->suspensions()->lift($this->moderator, $suspension->id(), 'Se ha disculpado');
        $this->forgetRequestState();

        $this->assertTrue(app(ForumAccessInterface::class)->canWrite($member, $this->discussion));
        $this->assertSame([ModerationAction::UserSuspended->value, ModerationAction::SuspensionLifted->value], ModerationLogModel::query()->orderBy('id')->pluck('action')->all());

        $this->expectException(InvalidModerationRecordException::class);
        $this->suspensions()->lift($this->moderator, $suspension->id());
    }

    public function test_a_new_suspension_takes_the_old_ones_place(): void
    {
        $member = $this->anAccount();
        $old = $this->suspensions()->suspend($this->moderator, $member->id, 'Primera vez', CarbonImmutable::now()->addDay());
        $new = $this->suspensions()->suspend($this->moderator, $member->id, 'Segunda vez', null);

        $stored = app(SuspensionRepositoryInterface::class);
        $this->assertNotNull($stored->find($old->id())?->liftedAt());
        $this->assertTrue($stored->activeFor($member->id, CarbonImmutable::now())?->id()->equals($new->id()));
    }

    public function test_only_whoever_moderates_the_whole_forum_suspends_and_never_a_peer_or_the_system(): void
    {
        $member = $this->anAccount();
        try {
            $this->suspensions()->suspend($this->anAccount(), $member->id, 'Porque sí', null);
            $this->fail('A reader suspended someone.');
        } catch (ForumActionDeniedException) {
        }

        foreach ([$this->moderator->id, $this->anAccount(self::MODERATE)->id, app(SystemAccountInterface::class)->id(), '0d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f'] as $id) {
            try {
                $this->suspensions()->suspend($this->moderator, $id, 'Prueba', null);
                $this->fail("{$id} was suspended.");
            } catch (ModerationRefusedException $exception) {
                $this->assertSame('not_suspendable', $exception->reason);
            }
        }

        $this->assertSame(0, ModerationLogModel::query()->count());
    }

    public function test_nobody_suspends_themselves_not_even_an_admin(): void
    {
        $admin = UserModel::factory()->admin()->create();

        foreach ([[$this->moderator, $this->moderator->id], [$admin, $admin->id]] as [$actor, $id]) {
            try {
                $this->suspensions()->suspend($actor, $id, 'Prueba', null);
                $this->fail('Someone suspended themselves.');
            } catch (ModerationRefusedException $exception) {
                $this->assertSame('not_suspendable', $exception->reason);
            }
        }
    }

    public function test_only_an_admin_suspends_whoever_moderates_the_whole_forum(): void
    {
        $peer = $this->anAccount(self::MODERATE);

        try {
            $this->suspensions()->suspend($this->moderator, $peer->id, 'Se suspenden entre sí', null);
            $this->fail('A forum moderator suspended another one.');
        } catch (ModerationRefusedException $exception) {
            $this->assertSame('not_suspendable', $exception->reason);
        }

        $admin = UserModel::factory()->admin()->create();
        $suspension = $this->suspensions()->suspend($admin, $peer->id, 'Abuso de la moderación', null);
        $this->forgetRequestState();

        $this->assertSame(strtolower($peer->id), $suspension->userId());
        $this->assertFalse(app(ForumAccessInterface::class)->canWrite($peer, $this->discussion));
    }

    public function test_suspensions_that_cannot_be_read_keep_everyone_from_writing(): void
    {
        $repository = $this->createStub(SuspensionRepositoryInterface::class);
        $repository->method('activeFor')->willThrowException(new RuntimeException('Database down'));
        $this->app->instance(SuspensionRepositoryInterface::class, $repository);
        $this->forgetRequestState();

        $this->assertTrue(app(ForumSuspensions::class)->isSuspended($this->author));
        $this->assertFalse(app(ForumAccessInterface::class)->canWrite($this->author, $this->discussion));
    }

    public function test_approving_announces_it_for_the_notices(): void
    {
        Event::fake([ForumPostApproved::class]);
        $pending = $this->aReply($this->discussion, '2026-11-10 18:30:00', needsApproval: true);

        app(ForumModeration::class)->approve($this->moderator, $pending->id());

        Event::assertDispatched(ForumPostApproved::class, fn (ForumPostApproved $event): bool => $event->postId === $pending->id()->value && ! $event->openedDiscussion);
    }

    private function forgetRequestState(): void
    {
        foreach ([ForumSuspensions::class, ForumAccessInterface::class, CategoryAccessPolicy::class] as $scoped) {
            $this->app->forgetInstance($scoped);
        }
    }

    private function reports(): ReportService
    {
        return app(ReportService::class);
    }

    private function suspensions(): SuspensionService
    {
        return app(SuspensionService::class);
    }
}
