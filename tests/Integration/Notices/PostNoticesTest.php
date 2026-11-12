<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Notices;

use App\Application\Mail\Services\MailPreferenceServiceInterface;
use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use App\Application\Services\UserServiceInterface;
use App\Application\SiteNotifications\DTOs\SiteNotificationItemDTO;
use App\Application\SiteNotifications\Services\SiteNotificationServiceInterface;
use App\Domain\Mail\Enums\MailDelivery;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Services\FollowService;
use Modules\Forum\Application\Services\ForumMailSubjects;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\PostLikeService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\CategoryFollowOrigin;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Notifications\DiscussionStartedNotification;
use Modules\Forum\Notifications\ForumMailCategories;
use Modules\Forum\Notifications\MentionedNotification;
use Modules\Forum\Notifications\PostLikedNotification;
use Modules\Forum\Notifications\ReplyPostedNotification;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeForumAccess;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Notices of what happens in the forum, delivered for real through the queue (sync in tests):
 * who gets each one, on which channel, and who never does.
 */
final class PostNoticesTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private FakeForumAccess $access;

    private UserModel $author;

    private UserModel $follower;

    private Category $category;

    private Discussion $discussion;

    private Post $firstPost;

    /**
     * Mails that went out: recipient id and notification class. The site channel reports every
     * notice as sent, also those the core then holds back, so site notices are read from the core.
     *
     * @var list<array{string, string}>
     */
    private array $mailed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->access = new FakeForumAccess;
        $this->app->instance(ForumAccessInterface::class, $this->access);

        $this->author = UserModel::factory()->create(['name' => 'Ana', 'display_name' => null]);
        $this->follower = UserModel::factory()->create(['name' => 'Luis', 'display_name' => null]);
        $this->access->letWrite($this->author, $this->follower);

        $this->category = $this->aCategory();
        [$this->discussion, $this->firstPost] = $this->aDiscussion($this->category, authorId: $this->author->id, title: 'La tumba');
        $this->follows()->setDiscussionLevel(strtolower($this->follower->id), $this->discussion->id(), FollowLevel::Following, CarbonImmutable::now());

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->channel === 'mail') {
                $this->mailed[] = [strtolower((string) $event->notifiable->getKey()), $event->notification::class];
            }
        });
    }

    public function test_a_reply_reaches_who_follows_the_discussion_on_the_site_and_by_mail(): void
    {
        $this->replyBy($this->author, 'Nos vemos el viernes');

        $this->assertSame([[strtolower($this->follower->id), ReplyPostedNotification::class]], $this->mailed);

        $notice = $this->latest($this->follower);
        $this->assertSame('Ana ha respondido en «La tumba»', $notice->title);
        $this->assertSame('Nos vemos el viernes', $notice->body);
        $this->assertStringStartsWith('/foro/t/', $notice->url);
    }

    public function test_a_reply_line_of_the_digest_names_who_wrote_and_goes_when_that_account_is_anonymized(): void
    {
        app(MailPreferenceServiceInterface::class)->update($this->follower, [ForumMailCategories::REPLIES => MailDelivery::Digest]);

        $this->replyBy($this->author, 'Nos vemos el viernes');

        $this->assertSame([], $this->mailsTo($this->follower));
        $line = DB::table('user_mail_digest_items')->where('user_id', $this->follower->id)->sole();
        $this->assertSame(strtolower($this->author->id), strtolower((string) $line->actor_id));

        app(UserServiceInterface::class)->anonymize((string) $this->author->id);

        $this->assertSame(0, DB::table('user_mail_digest_items')->where('user_id', $this->follower->id)->count());
    }

    public function test_a_new_discussion_line_of_the_digest_names_who_opened_it_and_goes_when_that_account_is_anonymized(): void
    {
        $follower = UserModel::factory()->create();
        $this->access->letRead($follower);
        $this->follows()->followCategory(strtolower($follower->id), $this->category->id(), CategoryFollowOrigin::Person, CarbonImmutable::now());
        app(MailPreferenceServiceInterface::class)->update($follower, [ForumMailCategories::DISCUSSIONS => MailDelivery::Digest]);

        $this->aDiscussion($this->category, authorId: $this->author->id, title: 'Nueva campaña');

        $this->assertSame(strtolower($this->author->id), strtolower((string) DB::table('user_mail_digest_items')->where('user_id', $follower->id)->sole()->actor_id));

        app(UserServiceInterface::class)->anonymize((string) $this->author->id);

        $this->assertSame(0, DB::table('user_mail_digest_items')->where('user_id', $follower->id)->count());
    }

    public function test_the_author_never_hears_about_their_own_post(): void
    {
        $this->follows()->setDiscussionLevel(strtolower($this->author->id), $this->discussion->id(), FollowLevel::Following, CarbonImmutable::now());

        $this->replyBy($this->author, 'Hola');

        $this->assertNothingFor($this->author);
    }

    public function test_a_follower_who_may_no_longer_read_the_discussion_hears_nothing(): void
    {
        $this->access = new FakeForumAccess;
        $this->access->letWrite($this->author);
        $this->app->instance(ForumAccessInterface::class, $this->access);

        $this->replyBy($this->author, 'Solo para algunos');

        $this->assertNothingFor($this->follower);
    }

    public function test_deactivated_anonymized_and_system_accounts_hear_nothing(): void
    {
        $deactivated = UserModel::factory()->create();
        $anonymized = UserModel::factory()->create(['anonymized_at' => now()]);
        $systemId = app(SystemAccountInterface::class)->id();
        foreach ([strtolower($deactivated->id), strtolower($anonymized->id), $systemId] as $id) {
            DB::table('forum_discussion_follows')->insert(['user_id' => $id, 'discussion_id' => $this->discussion->id()->value, 'level' => 'following', 'updated_at' => now()]);
        }
        $deactivated->delete();
        $this->access->letRead($deactivated, $anonymized);

        $this->replyBy($this->author, 'Hola');

        $this->assertSame([strtolower($this->follower->id)], array_values(array_unique(array_column($this->mailed, 0))));
        $this->assertNothingFor($anonymized);
        $this->assertSame(0, DB::table('site_notifications')->where('user_id', '!=', $this->follower->id)->count());
    }

    public function test_replies_gather_in_one_site_notice_and_one_mail_until_the_discussion_is_read_again(): void
    {
        $other = UserModel::factory()->create(['name' => 'Eva', 'display_name' => null]);
        $this->access->letWrite($other);

        $this->replyBy($this->author, 'Uno');
        $this->replyBy($other, 'Dos');

        $this->assertCount(1, $this->mailsTo($this->follower));
        $notice = $this->latest($this->follower);
        $this->assertSame(2, $notice->count);
        $this->assertSame('Eva y otra persona han respondido en «La tumba»', $notice->title);
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->unreadCount($this->follower));

        // Once they read up to the latest reply, the next one mails again
        app(DiscussionReadsInterface::class)->advance(strtolower($this->follower->id), $this->discussion->id(), 3, CarbonImmutable::now());
        $this->replyBy($this->author, 'Tres');
        $this->assertCount(2, $this->mailsTo($this->follower));
    }

    public function test_a_muted_discussion_sends_nothing_at_all(): void
    {
        app(FollowService::class)->setLevel($this->follower, $this->discussion->id(), FollowLevel::Muted);

        $this->replyBy($this->author, 'Hola', mentions: [$this->follower]);

        $this->assertNothingFor($this->follower);
    }

    public function test_a_mention_reaches_the_person_once_even_when_they_follow_the_discussion(): void
    {
        $friend = UserModel::factory()->create(['name' => 'Eva', 'display_name' => null]);
        $this->access->letRead($friend);

        $this->replyBy($this->author, 'Mirad esto', mentions: [$this->follower, $friend]);

        $this->assertSame([MentionedNotification::class], array_column($this->mailsTo($this->follower), 1));
        $this->assertSame([MentionedNotification::class], array_column($this->mailsTo($friend), 1));
        $this->assertSame(1, app(SiteNotificationServiceInterface::class)->total($this->follower));
        $this->assertSame('Ana te ha mencionado en «La tumba»', $this->latest($friend)->title);
    }

    public function test_a_mention_of_someone_who_may_not_read_the_discussion_tells_them_nothing(): void
    {
        $stranger = UserModel::factory()->create();

        $this->replyBy($this->author, 'Hola', mentions: [$stranger]);

        $this->assertNothingFor($stranger);
    }

    public function test_quoting_a_post_tells_its_author(): void
    {
        $quote = ['type' => 'postQuote', 'attrs' => ['postId' => $this->firstPost->id()->value], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Primer mensaje']]]]];

        $this->replyBy($this->follower, 'Eso', extra: [$quote]);

        $this->assertSame([MentionedNotification::class], array_column($this->mailsTo($this->author), 1));
        $this->assertSame('Luis ha citado tu mensaje en «La tumba»', $this->latest($this->author)->title);
    }

    public function test_an_edit_tells_only_who_it_newly_mentions(): void
    {
        $friend = UserModel::factory()->create();
        $this->access->letRead($friend);
        $reply = $this->replyBy($this->author, 'Hola', mentions: [$this->follower]);
        $this->mailed = [];

        app(ForumWriter::class)->editPost($reply->id(), $this->body('Hola de nuevo', [$this->follower, $friend]), strtolower($this->author->id), CarbonImmutable::now());

        $this->assertSame([], $this->mailsTo($this->follower));
        $this->assertSame([MentionedNotification::class], array_column($this->mailsTo($friend), 1));
    }

    public function test_a_new_discussion_reaches_who_follows_its_category_or_the_category_above(): void
    {
        $child = $this->aCategoryFor(CategoryAudience::signedIn(), 'hija', parent: $this->category);
        $parentFollower = UserModel::factory()->create();
        $childFollower = UserModel::factory()->create();
        $this->access->letRead($parentFollower, $childFollower);
        $this->follows()->followCategory(strtolower($parentFollower->id), $this->category->id(), CategoryFollowOrigin::Person, CarbonImmutable::now());
        $this->follows()->followCategory(strtolower($childFollower->id), $child->id(), CategoryFollowOrigin::Person, CarbonImmutable::now());
        $this->mailed = [];

        $this->aDiscussion($child, authorId: $this->author->id, title: 'Nueva campaña');

        foreach ([$parentFollower, $childFollower] as $user) {
            $this->assertSame([DiscussionStartedNotification::class], array_column($this->mailsTo($user), 1));
        }
        $this->assertSame('Ana ha abierto «Nueva campaña» en Hija', $this->latest($childFollower)->title);
        // Followers of the discussion where nothing new was posted hear nothing
        $this->assertNothingFor($this->follower);
    }

    public function test_a_like_reaches_the_author_on_the_site_only_and_likes_of_a_post_gather(): void
    {
        $other = UserModel::factory()->create(['name' => 'Eva', 'display_name' => null]);
        $this->access->letRead($other);

        app(PostLikeService::class)->like($this->follower, $this->firstPost->id());
        app(PostLikeService::class)->like($other, $this->firstPost->id());

        $this->assertSame([], $this->mailed);
        $notice = $this->latest($this->author);
        $this->assertSame(2, $notice->count);
        $this->assertSame('A Eva y a otra persona les ha gustado tu mensaje en «La tumba»', $notice->title);
    }

    public function test_a_like_taken_back_before_its_notice_arrives_tells_nothing(): void
    {
        $notification = new PostLikedNotification($this->firstPost->id()->value, strtolower($this->follower->id));

        $this->assertFalse($notification->shouldSend($this->author, 'site'));
    }

    public function test_deleting_a_discussion_forgets_its_mutes_and_notices(): void
    {
        $this->replyBy($this->author, 'Hola');
        app(MailSubjectMuteServiceInterface::class)->mute($this->follower, ForumMailSubjects::discussion($this->discussion));

        app(ForumWriter::class)->deleteDiscussion($this->discussion->id());

        $this->assertSame([], app(MailSubjectMuteServiceInterface::class)->mutedFor($this->follower));
        $this->assertSame(0, app(SiteNotificationServiceInterface::class)->total($this->follower));
    }

    public function test_the_writer_alone_follows_nothing(): void
    {
        $discussion = Discussion::start(DiscussionId::generate(), $this->category->id(), strtolower($this->author->id), new DiscussionTitle('Otra'), new CarbonImmutable);
        app(ForumWriter::class)->startDiscussion($discussion, PostId::generate(), self::aBody('Hola'), needsApproval: false);

        // The writer alone follows nothing: starting from the forum does (DiscussionStarter)
        $this->assertSame([], $this->follows()->discussionFollowers($discussion->id()));
    }

    /**
     * @param  list<UserModel>  $mentions
     * @param  list<array<string, mixed>>  $extra
     */
    private function replyBy(UserModel $author, string $text, array $mentions = [], array $extra = []): Post
    {
        return app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($author->id), $this->body($text, $mentions, $extra), CarbonImmutable::now(), needsApproval: false);
    }

    /**
     * @param  list<UserModel>  $mentions
     * @param  list<array<string, mixed>>  $extra
     */
    private function body(string $text, array $mentions = [], array $extra = []): PostBody
    {
        $inline = [['type' => 'text', 'text' => $text]];
        foreach ($mentions as $user) {
            $inline[] = ['type' => 'mention', 'attrs' => ['id' => strtolower($user->id)]];
        }

        return new PostBody(['type' => 'doc', 'content' => [...$extra, ['type' => 'paragraph', 'content' => $inline]]], $text);
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

    private function follows(): FollowsInterface
    {
        return app(FollowsInterface::class);
    }
}
