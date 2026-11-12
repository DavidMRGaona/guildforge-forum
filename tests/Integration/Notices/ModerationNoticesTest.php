<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Notices;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Mail\Services\MailCategoryRegistryInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Application\SiteNotifications\DTOs\SiteNotificationItemDTO;
use App\Application\SiteNotifications\Services\SiteNotificationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\ReportService;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Events\ForumUserSuspensionLifted;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Notifications\ForumMailCategories;
use Modules\Forum\Notifications\PostApprovedNotification;
use Modules\Forum\Notifications\PostReportedNotification;
use Modules\Forum\Notifications\ReplyPostedNotification;
use Modules\Forum\Notifications\SuspensionLiftedNotification;
use Modules\Forum\Notifications\UserSuspendedNotification;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What moderation tells people, delivered for real through the queue (sync in tests) with the
 * real access rules: the author of a post that was approved, the person who was suspended and
 * the moderators of a reported post.
 */
final class ModerationNoticesTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MODERATE = 'forum:forum.moderate';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $category;

    private UserModel $author;

    private UserModel $moderator;

    private Discussion $discussion;

    private Post $firstPost;

    /** @var list<array{string, string}> recipient id and notification class of every mail that went out */
    private array $mailed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->author = $this->anAccount();
        $this->moderator = $this->anAccount(self::MODERATE);
        [$this->discussion, $this->firstPost] = $this->aDiscussion($this->category, authorId: strtolower($this->author->id), title: 'La tumba');

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->channel === 'mail') {
                $this->mailed[] = [strtolower((string) $event->notifiable->getKey()), $event->notification::class];
            }
        });
    }

    // Approved

    public function test_approving_a_reply_tells_its_author_once_and_followers_hear_about_it_once(): void
    {
        $follower = $this->anAccount();
        $this->follows($follower);
        $pending = $this->pendingReply($this->author, 'Mi respuesta');
        $this->assertNothingFor($follower);

        $this->moderation()->approve($this->moderator, $pending->id());

        $this->assertSame([PostApprovedNotification::class], array_column($this->mailsTo($this->author), 1));
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->total($this->author));
        $this->assertSame('Tu mensaje se ha publicado en «La tumba»', $this->latest($this->author)->title);
        $this->assertStringStartsWith('/foro/t/', $this->latest($this->author)->url);

        $this->assertSame([ReplyPostedNotification::class], array_column($this->mailsTo($follower), 1));
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->total($follower));
    }

    public function test_approving_the_first_post_of_a_waiting_discussion_says_the_discussion_was_published(): void
    {
        [, $first] = $this->aDiscussion($this->category, authorId: strtolower($this->author->id), needsApproval: true, title: 'Nueva campaña');

        $this->moderation()->approve($this->moderator, $first->id());

        $this->assertSame('Tu tema «Nueva campaña» se ha publicado', $this->latest($this->author)->title);
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->total($this->author));
    }

    public function test_an_author_who_is_gone_or_may_not_read_the_post_any_more_hears_nothing(): void
    {
        $pending = $this->pendingReply($this->author, 'Hola');
        $this->author->delete();

        $this->moderation()->approve($this->moderator, $pending->id());

        $this->assertSame([], $this->mailsTo($this->author));
        $this->assertSame(0, app(SiteNotificationServiceInterface::class)->total($this->author));
    }

    public function test_approved_notices_are_optional_and_not_digestible(): void
    {
        $category = app(MailCategoryRegistryInterface::class)->find(ForumMailCategories::APPROVALS);

        $this->assertNotNull($category);
        $this->assertTrue($category->site && $category->mail && ! $category->digestible && ! $category->isMandatory());
    }

    // Suspended

    public function test_a_suspension_reaches_the_person_on_the_site_and_by_mail_with_the_reason_and_the_end(): void
    {
        $suspended = $this->anAccount();
        $endsAt = new CarbonImmutable('2026-12-20 18:00:00');

        $this->suspensions()->suspend($this->moderator, $suspended->id, 'Insultos reiterados', $endsAt);

        $this->assertSame([UserSuspendedNotification::class], array_column($this->mailsTo($suspended), 1));
        $notice = $this->latest($suspended);
        $until = app(TimezoneServiceInterface::class)->format($endsAt);
        $this->assertSame("Se te ha suspendido en el foro hasta el {$until}", $notice->title);
        $this->assertSame('Motivo: Insultos reiterados', $notice->body);
    }

    public function test_a_suspension_without_an_end_says_until_it_is_lifted(): void
    {
        $suspended = $this->anAccount();

        $this->suspensions()->suspend($this->moderator, $suspended->id, 'Spam', null);

        $this->assertSame('Se te ha suspendido en el foro hasta que se levante', $this->latest($suspended)->title);
    }

    public function test_suspension_notices_are_mandatory_and_never_wait_for_a_digest(): void
    {
        $category = app(MailCategoryRegistryInterface::class)->find(ForumMailCategories::SUSPENSIONS);

        $this->assertNotNull($category);
        $this->assertTrue($category->site && $category->mail && ! $category->digestible && $category->isMandatory());
    }

    public function test_a_suspension_lifted_before_its_notice_arrives_tells_nothing(): void
    {
        $suspended = $this->anAccount();
        $suspension = $this->suspensions()->suspend($this->moderator, $suspended->id, 'Spam', null);
        $notification = new UserSuspendedNotification($suspension->id()->value);
        $this->assertTrue($notification->shouldSend($suspended, 'mail'));

        $this->suspensions()->lift($this->moderator, $suspension->id());

        $this->assertFalse($notification->shouldSend($suspended, 'mail'));
        $this->assertFalse($notification->shouldSend($suspended, 'site'));
    }

    public function test_a_suspension_replaced_by_a_newer_one_only_notifies_for_the_newer(): void
    {
        $suspended = $this->anAccount();
        $first = $this->suspensions()->suspend($this->moderator, $suspended->id, 'Primera', null);
        $this->suspensions()->suspend($this->moderator, $suspended->id, 'Segunda', null);

        $this->assertFalse((new UserSuspendedNotification($first->id()->value))->shouldSend($suspended, 'mail'));
    }

    public function test_a_deactivated_account_is_not_told_about_its_suspension(): void
    {
        $suspended = $this->anAccount();
        $suspension = $this->suspensions()->suspend($this->moderator, $suspended->id, 'Spam', null);
        $notification = new UserSuspendedNotification($suspension->id()->value);
        $this->assertTrue($notification->shouldSend($suspended, 'site'));

        $suspended->delete();

        $this->assertFalse($notification->shouldSend($suspended, 'site'));
    }

    // Suspension lifted

    public function test_lifting_a_suspension_tells_the_person_on_the_site_and_by_mail(): void
    {
        $suspended = $this->anAccount();
        $suspension = $this->suspensions()->suspend($this->moderator, $suspended->id, 'Spam', null);
        $this->mailed = [];
        $before = app(SiteNotificationServiceInterface::class)->total($suspended);

        $this->suspensions()->lift($this->moderator, $suspension->id(), 'Se ha disculpado');

        $this->assertSame([SuspensionLiftedNotification::class], array_column($this->mailsTo($suspended), 1));
        $this->assertSame($before + 1, app(SiteNotificationServiceInterface::class)->total($suspended));
        $notice = $this->latest($suspended);
        $this->assertSame('Se ha levantado tu suspensión en el foro', $notice->title);
        $this->assertStringStartsWith('/foro', $notice->url);
        // Nothing of the reason a moderator gave, nor who lifted it
        $this->assertStringNotContainsString('disculpado', (string) $notice->body);
        $this->assertSame([], $this->mailsTo($this->moderator));
    }

    public function test_the_event_carries_the_suspension_the_person_and_who_lifted_it(): void
    {
        $suspended = $this->anAccount();
        $suspension = $this->suspensions()->suspend($this->moderator, $suspended->id, 'Spam', null);
        Event::fake([ForumUserSuspensionLifted::class]);

        $this->suspensions()->lift($this->moderator, $suspension->id());

        Event::assertDispatched(ForumUserSuspensionLifted::class, fn (ForumUserSuspensionLifted $event): bool => $event->suspensionId === $suspension->id()->value
            && $event->userId === strtolower($suspended->id)
            && $event->liftedBy === strtolower($this->moderator->id));
    }

    public function test_a_failed_lift_dispatches_nothing(): void
    {
        $suspended = $this->anAccount();
        $suspension = $this->suspensions()->suspend($this->moderator, $suspended->id, 'Spam', null);
        $this->suspensions()->lift($this->moderator, $suspension->id());
        Event::fake([ForumUserSuspensionLifted::class]);

        try {
            $this->suspensions()->lift($this->moderator, $suspension->id());
            $this->fail('A suspension was lifted twice.');
        } catch (InvalidModerationRecordException) {
            // expected
        }

        Event::assertNotDispatched(ForumUserSuspensionLifted::class);
    }

    public function test_lifting_an_end_already_past_does_not_announce_anything(): void
    {
        $suspended = $this->anAccount();
        $suspension = $this->suspensions()->suspend($this->moderator, $suspended->id, 'Spam', CarbonImmutable::now()->addHour());
        $this->mailed = [];
        $total = app(SiteNotificationServiceInterface::class)->total($suspended);
        $this->travel(2)->hours();
        app()->forgetScopedInstances();

        $this->suspensions()->lift($this->moderator, $suspension->id());

        $this->assertSame([], $this->mailsTo($suspended));
        $this->assertSame($total, app(SiteNotificationServiceInterface::class)->total($suspended));
    }

    public function test_lifting_one_suspension_while_another_is_in_force_tells_nothing(): void
    {
        $suspended = $this->anAccount();
        $first = $this->suspensions()->suspend($this->moderator, $suspended->id, 'Primera', null);
        // The newer one replaces the older, which is then lifted by the replacement itself
        $second = $this->suspensions()->suspend($this->moderator, $suspended->id, 'Segunda', null);
        $this->mailed = [];
        $total = app(SiteNotificationServiceInterface::class)->total($suspended);

        // A notice for the replaced one, delivered late, finds the newer suspension in force
        $notification = new SuspensionLiftedNotification($first->id()->value);
        $this->assertFalse($notification->shouldSend($suspended, 'mail'));
        $this->assertFalse($notification->shouldSend($suspended, 'site'));
        $this->assertSame([], $this->mailsTo($suspended));
        $this->assertSame($total, app(SiteNotificationServiceInterface::class)->total($suspended));

        // And once the newer one is lifted, the person does hear it
        $this->suspensions()->lift($this->moderator, $second->id());
        $this->assertSame([SuspensionLiftedNotification::class], array_column($this->mailsTo($suspended), 1));
    }

    public function test_an_anonymized_or_deactivated_account_is_not_told_its_suspension_was_lifted(): void
    {
        $anonymized = $this->anAccount();
        $suspension = $this->suspensions()->suspend($this->moderator, $anonymized->id, 'Spam', null);
        $this->mailed = [];
        $total = app(SiteNotificationServiceInterface::class)->total($anonymized);
        $anonymized->forceFill(['anonymized_at' => now()])->save();

        $this->suspensions()->lift($this->moderator, $suspension->id());

        $this->assertSame([], $this->mailsTo($anonymized));
        $this->assertSame($total, app(SiteNotificationServiceInterface::class)->total($anonymized));

        $deactivated = $this->anAccount();
        $other = $this->suspensions()->suspend($this->moderator, $deactivated->id, 'Spam', null);
        $this->mailed = [];
        $notification = new SuspensionLiftedNotification($other->id()->value);
        $this->suspensions()->lift($this->moderator, $other->id());
        $deactivated->delete();

        $this->assertFalse($notification->shouldSend($deactivated, 'site'));
    }

    public function test_the_system_account_is_never_told_its_suspension_was_lifted(): void
    {
        $system = UserModel::query()->findOrFail(app(SystemAccountInterface::class)->id());

        $this->assertFalse((new SuspensionLiftedNotification((string) Str::uuid()))->shouldSend($system, 'mail'));
        $this->assertSame([], $this->mailsTo($system));
    }

    public function test_suspension_lifted_notices_use_the_mandatory_category(): void
    {
        $this->assertSame(ForumMailCategories::SUSPENSIONS, (new SuspensionLiftedNotification('x'))->mailCategory());
    }

    // Reported

    public function test_a_report_reaches_who_moderates_the_post_and_nobody_else(): void
    {
        $roleModerator = $this->anAccount();
        $this->makeCategoryModerators($roleModerator);
        $reader = $this->anAccount();
        $reporter = $this->anAccount();

        $this->reports()->report($reporter, $this->firstPost->id(), ReportReason::Offensive, 'No me gusta cómo habla');

        foreach ([$this->moderator, $roleModerator] as $moderator) {
            $this->assertSame([PostReportedNotification::class], array_column($this->mailsTo($moderator), 1));
            $notice = $this->latest($moderator);
            $this->assertSame('Han denunciado un mensaje en «La tumba»', $notice->title);
            $this->assertStringContainsString('Ofensivo', (string) $notice->body);
        }
        $this->assertNothingFor($reader);
        $this->assertNothingFor($reporter);
        $this->assertNothingFor($this->author);
    }

    public function test_the_notice_never_says_who_reported_nor_what_they_wrote(): void
    {
        $reporter = $this->anAccount();
        $reporter->forceFill(['name' => 'Denunciante Secreto', 'display_name' => null])->save();

        $this->reports()->report($reporter, $this->firstPost->id(), ReportReason::Other, 'Nota privada de quien denuncia');

        $notice = $this->latest($this->moderator);
        $this->assertStringNotContainsString('Denunciante Secreto', $notice->title.$notice->body);
        $this->assertStringNotContainsString('Nota privada', $notice->title.$notice->body);
    }

    public function test_a_moderator_who_reports_is_not_told_about_their_own_report(): void
    {
        $other = $this->anAccount(self::MODERATE);

        $this->reports()->report($this->moderator, $this->firstPost->id(), ReportReason::Spam, null);

        $this->assertNothingFor($this->moderator);
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->total($other));
    }

    public function test_reports_of_one_post_gather_in_one_site_notice_and_one_mail_while_they_are_open(): void
    {
        $this->reports()->report($this->anAccount(), $this->firstPost->id(), ReportReason::Spam, null);
        $this->travel(5)->seconds();
        $this->reports()->report($this->anAccount(), $this->firstPost->id(), ReportReason::Offensive, null);

        $this->assertCount(1, $this->mailsTo($this->moderator));
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->unreadCount($this->moderator));
        $notice = $this->latest($this->moderator);
        $this->assertSame(2, $notice->count);
        $this->assertSame('Han denunciado 2 veces un mensaje en «La tumba»', $notice->title);
    }

    public function test_reports_of_different_posts_do_not_gather(): void
    {
        $reply = app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($this->author->id), self::aBody('Otra'), CarbonImmutable::now(), needsApproval: false);

        $this->reports()->report($this->anAccount(), $this->firstPost->id(), ReportReason::Spam, null);
        $this->reports()->report($this->anAccount(), $reply->id(), ReportReason::Spam, null);

        $this->assertSame(2, app(SiteNotificationServiceInterface::class)->unreadCount($this->moderator));
        $this->assertCount(2, $this->mailsTo($this->moderator));
    }

    public function test_a_report_settled_before_its_notice_arrives_tells_nothing(): void
    {
        $report = $this->reports()->report($this->anAccount(), $this->firstPost->id(), ReportReason::Spam, null);
        $notification = new PostReportedNotification($report->id()->value, $this->firstPost->id()->value);
        $this->assertTrue($notification->shouldSend($this->moderator, 'site'));

        $this->reports()->resolve($this->moderator, $this->firstPost->id(), ReportResolution::NoAction);

        $this->assertFalse($notification->shouldSend($this->moderator, 'site'));
        $this->assertFalse($notification->shouldSend($this->moderator, 'mail'));
    }

    public function test_someone_who_no_longer_moderates_the_post_is_not_told_when_it_is_delivered(): void
    {
        $roleModerator = $this->anAccount();
        $role = $this->makeCategoryModerators($roleModerator);
        $report = $this->reports()->report($this->anAccount(), $this->firstPost->id(), ReportReason::Spam, null);
        $notification = new PostReportedNotification($report->id()->value, $this->firstPost->id()->value);
        $this->assertTrue($notification->shouldSend($roleModerator, 'site'));

        $roleModerator->roles()->detach($role->id);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($roleModerator);
        app()->forgetScopedInstances();

        $this->assertFalse($notification->shouldSend($roleModerator, 'site'));
    }

    public function test_a_suspended_moderator_is_not_told_about_reports(): void
    {
        $suspended = $this->anAccount();
        $this->makeCategoryModerators($suspended);
        $this->suspensions()->suspend($this->moderator, $suspended->id, 'Spam', null);
        app()->forgetScopedInstances();

        $this->reports()->report($this->anAccount(), $this->firstPost->id(), ReportReason::Spam, null);

        // The only thing the suspended moderator heard is their own suspension
        $this->assertSame([UserSuspendedNotification::class], array_column($this->mailsTo($suspended), 1));
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->total($suspended));
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->total($this->moderator));
    }

    public function test_the_system_account_is_never_told_even_if_it_holds_the_moderation_permission(): void
    {
        $system = UserModel::query()->findOrFail(app(SystemAccountInterface::class)->id());
        $role = RoleModel::query()->create(['name' => 'role-'.strtolower(uniqid()), 'display_name' => 'Role']);
        $role->permissions()->attach(PermissionModel::query()->firstOrCreate(['key' => self::MODERATE], ['label' => self::MODERATE, 'resource' => 'forum', 'action' => 'any', 'module' => 'forum'])->id);
        $system->roles()->attach($role->id);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($system);

        $this->reports()->report($this->anAccount(), $this->firstPost->id(), ReportReason::Spam, null);

        $this->assertNothingFor($system);
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->total($this->moderator));
    }

    public function test_report_notices_are_optional_and_not_digestible(): void
    {
        $category = app(MailCategoryRegistryInterface::class)->find(ForumMailCategories::REPORTS);

        $this->assertNotNull($category);
        $this->assertTrue($category->site && $category->mail && ! $category->digestible && ! $category->isMandatory());
    }

    private function pendingReply(UserModel $author, string $text): Post
    {
        return app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($author->id), self::aBody($text), CarbonImmutable::now(), needsApproval: true);
    }

    private function follows(UserModel $user): void
    {
        app(FollowsInterface::class)->setDiscussionLevel(strtolower($user->id), $this->discussion->id(), FollowLevel::Following, CarbonImmutable::now());
    }

    /**
     * Gives the user a role that moderates the category (and nothing else).
     */
    private function makeCategoryModerators(UserModel $user): RoleModel
    {
        $role = RoleModel::query()->create(['name' => 'mods-'.strtolower(uniqid()), 'display_name' => 'Mods']);
        $user->roles()->attach($role->id);

        $this->category->changeAccess(CategoryAudience::everyone(), CategoryAudience::signedIn(), new CategoryModerators([strtolower((string) $role->id)]));
        app(CategoryRepositoryInterface::class)->save($this->category);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($user);
        app()->forgetScopedInstances();

        return $role;
    }

    /**
     * @return list<array{string, string}>
     */
    private function mailsTo(UserModel $user): array
    {
        return array_values(array_filter($this->mailed, static fn (array $mail): bool => $mail[0] === strtolower($user->id)));
    }

    private function assertNothingFor(UserModel $user): void
    {
        $this->assertSame([], $this->mailsTo($user));
        $this->assertSame(0, app(SiteNotificationServiceInterface::class)->total($user));
    }

    private function latest(UserModel $user): SiteNotificationItemDTO
    {
        $items = app(SiteNotificationServiceInterface::class)->latest($user, 1);
        $this->assertNotSame([], $items);

        return $items[0];
    }

    private function moderation(): ForumModeration
    {
        return app(ForumModeration::class);
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
