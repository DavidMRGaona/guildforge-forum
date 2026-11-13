<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Application\Realtime\Services\RealtimePublisherInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Realtime\BroadcastRealtimePublisher;
use App\Infrastructure\Realtime\RealtimeBroadcast;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Services\ConversationReader;
use Modules\Forum\Application\Services\FollowService;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\PostLikeService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\CategoryFollowOrigin;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Jobs\SignalFollowersJob;
use Modules\Forum\Tests\Support\BuildsConversations;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\RecordingRealtimePublisher;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Realtime\EnablesRealtime;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What the forum tells open pages in real time after each write: only ids and numbers, on the
 * channel of what changed, and nothing at all while real time is off.
 */
final class ForumLiveSignalsTest extends ModuleTestCase
{
    use BuildsConversations;
    use BuildsForumContent;
    use EnablesRealtime;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private RecordingRealtimePublisher $realtime;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->enableRealtime();
        $this->realtime = new RecordingRealtimePublisher;
        $this->app->instance(RealtimePublisherInterface::class, $this->realtime);
        $this->category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
    }

    public function test_a_reply_tells_its_discussion_and_its_category(): void
    {
        [$discussion] = $this->aDiscussion($this->category);
        $this->realtime->forget();

        $this->aReply($discussion, '2026-11-10 18:05:00');

        self::assertSame([['event' => 'post.created', 'payload' => ['number' => 2]]], $this->realtime->on($this->discussionChannel($discussion)));
        self::assertSame(
            [['event' => 'discussion.activity', 'payload' => ['discussion' => $discussion->id()->value, 'number' => 2]]],
            $this->realtime->on('forum.category.'.$this->category->id()->value),
        );
    }

    public function test_a_new_discussion_tells_its_category(): void
    {
        [$discussion] = $this->aDiscussion($this->category);

        self::assertSame(
            [['event' => 'discussion.activity', 'payload' => ['discussion' => $discussion->id()->value, 'number' => 1]]],
            $this->realtime->on('forum.category.'.$this->category->id()->value),
        );
    }

    public function test_a_post_awaiting_approval_tells_nobody_until_it_is_approved(): void
    {
        [$discussion] = $this->aDiscussion($this->category);
        $this->realtime->forget();

        $pending = $this->aReply($discussion, '2026-11-10 18:05:00', needsApproval: true);
        self::assertSame([], $this->realtime->messages);

        app(ForumWriter::class)->approvePost($pending->id(), new DateTimeImmutable('2026-11-10 18:10:00'));
        self::assertSame([['event' => 'post.created', 'payload' => ['number' => 2]]], $this->realtime->on($this->discussionChannel($discussion)));
    }

    public function test_activity_in_a_hidden_discussion_stays_off_its_category(): void
    {
        [$discussion] = $this->aDiscussion($this->category);
        app(ForumWriter::class)->changeDiscussion($discussion->id(), static fn (Discussion $hidden) => $hidden->hide());
        $this->realtime->forget();

        $this->aReply($discussion, '2026-11-10 18:05:00');

        self::assertSame([['event' => 'post.created', 'payload' => ['number' => 2]]], $this->realtime->on($this->discussionChannel($discussion)));
        self::assertSame([], $this->realtime->on('forum.category.'.$this->category->id()->value));
    }

    public function test_edits_deletions_and_moderation_of_a_post_say_it_changed(): void
    {
        $author = UserModel::factory()->create();
        [$discussion] = $this->aDiscussion($this->category);
        $reply = $this->aReplyBy($discussion, $author);
        $writer = app(ForumWriter::class);
        $this->realtime->forget();

        $writer->editPost($reply->id(), self::aBody('Corregido'), strtolower((string) $author->id), new DateTimeImmutable('2026-11-10 18:20:00'));
        $writer->changePost($reply->id(), static fn (Post $post) => $post->hide('Fuera de tema'));
        $writer->changePost($reply->id(), static fn (Post $post) => $post->restore());
        $writer->deletePostByAuthor($reply->id(), new DateTimeImmutable('2026-11-10 18:30:00'));

        $changed = ['event' => 'post.changed', 'payload' => ['number' => 2]];
        self::assertSame([$changed, $changed, $changed, $changed], $this->realtime->on($this->discussionChannel($discussion)));
    }

    public function test_a_like_and_taking_it_back_say_the_post_changed(): void
    {
        $author = UserModel::factory()->create();
        $liker = UserModel::factory()->create();
        [$discussion] = $this->aDiscussion($this->category);
        $reply = $this->aReplyBy($discussion, $author);
        $this->realtime->forget();

        app(PostLikeService::class)->like($liker, $reply->id());
        app(PostLikeService::class)->unlike($liker, $reply->id());

        $changed = ['event' => 'post.changed', 'payload' => ['number' => 2]];
        self::assertSame([$changed, $changed], $this->realtime->on($this->discussionChannel($discussion)));
    }

    public function test_closing_merging_and_deleting_say_the_discussion_changed(): void
    {
        [$first] = $this->aDiscussion($this->category);
        [$second] = $this->aDiscussion($this->category, title: 'Otra');
        [$third] = $this->aDiscussion($this->category, title: 'Tercera');
        $writer = app(ForumWriter::class);
        $this->realtime->forget();

        $writer->changeDiscussion($first->id(), static fn (Discussion $discussion) => $discussion->close());
        $writer->mergeDiscussion($second->id(), $first->id(), new DateTimeImmutable('2026-11-10 19:00:00'));
        $writer->deleteDiscussion($third->id());

        $changed = ['event' => 'discussion.changed', 'payload' => []];
        self::assertSame([$changed, $changed], $this->realtime->on($this->discussionChannel($first)));
        self::assertSame([$changed], $this->realtime->on($this->discussionChannel($second)));
        self::assertSame([$changed], $this->realtime->on($this->discussionChannel($third)));
    }

    public function test_whoever_follows_the_discussion_or_its_category_hears_the_pulse_but_its_author(): void
    {
        $author = UserModel::factory()->create();
        $follower = UserModel::factory()->create();
        $categoryFollower = UserModel::factory()->create();
        $stranger = UserModel::factory()->create();
        [$discussion] = $this->aDiscussion($this->category);
        $follows = app(FollowsInterface::class);
        $follows->setDiscussionLevel($this->id($follower), $discussion->id(), FollowLevel::Following, new DateTimeImmutable('2026-11-10 18:01:00'));
        $follows->setDiscussionLevel($this->id($author), $discussion->id(), FollowLevel::Following, new DateTimeImmutable('2026-11-10 18:01:00'));
        $follows->followCategory($this->id($categoryFollower), $this->category->id(), CategoryFollowOrigin::Person, new DateTimeImmutable('2026-11-10 18:01:00'));
        $this->realtime->forget();

        $this->aReplyBy($discussion, $author);

        $heard = $this->realtime->channelsOf('pulse.changed');
        sort($heard);
        $expected = ['forum.account.'.$this->id($follower), 'forum.account.'.$this->id($categoryFollower)];
        sort($expected);
        self::assertSame($expected, $heard);
        self::assertSame([], $this->realtime->on('forum.account.'.$this->id($stranger)));
        self::assertSame([['event' => 'pulse.changed', 'payload' => []]], $this->realtime->on('forum.account.'.$this->id($follower)));
    }

    public function test_a_follower_who_may_no_longer_read_the_discussion_hears_nothing(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios', position: 1);
        $follower = UserModel::factory()->create();
        [$discussion] = $this->aDiscussion($members);
        app(FollowsInterface::class)->setDiscussionLevel($this->id($follower), $discussion->id(), FollowLevel::Following, new DateTimeImmutable('2026-11-10 18:01:00'));
        $this->realtime->forget();

        $this->aReplyBy($discussion, UserModel::factory()->create());

        self::assertSame([], $this->realtime->channelsOf('pulse.changed'));
    }

    public function test_reading_to_the_end_and_following_change_ones_own_pulse(): void
    {
        $reader = UserModel::factory()->create();
        [$discussion] = $this->aDiscussion($this->category);
        $this->aReply($discussion, '2026-11-10 18:05:00');
        $this->aReply($discussion, '2026-11-10 18:06:00');
        $own = 'forum.account.'.$this->id($reader);
        $this->realtime->forget();

        app(ConversationReader::class)->markRead($reader, $discussion->id(), 2, CarbonImmutable::now());
        self::assertNotContains($own, $this->realtime->channelsOf('pulse.changed'), 'Still one post to read: nothing the menu counts changed');

        app(ConversationReader::class)->markRead($reader, $discussion->id(), 3, CarbonImmutable::now());
        app(ConversationReader::class)->markRead($reader, $discussion->id(), 3, CarbonImmutable::now());
        self::assertCount(1, $this->realtime->on($own), 'Only reaching the end changes the count, once');

        app(FollowService::class)->setLevel($reader, $discussion->id(), FollowLevel::Following);
        self::assertCount(2, array_filter($this->realtime->messages, static fn (array $message): bool => $message['channel'] === $own));
    }

    public function test_a_private_message_tells_its_conversation_and_the_others_in_it(): void
    {
        $ana = UserModel::factory()->create();
        $luis = UserModel::factory()->create();
        $conversation = $this->aConversation($ana, $luis);
        $channel = 'forum.conversation.'.$conversation->id()->value;
        $this->realtime->forget();

        $this->conversations()->reply($luis, $conversation->id(), $this->messageDocument('¿A qué hora?'));

        self::assertSame([['event' => 'entry.created', 'payload' => ['number' => 2]]], $this->realtime->on($channel));
        self::assertSame(['forum.account.'.$this->id($ana)], $this->realtime->channelsOf('pulse.changed'));
    }

    public function test_adding_leaving_and_deleting_in_a_conversation(): void
    {
        $ana = UserModel::factory()->create();
        $luis = UserModel::factory()->create();
        $eva = UserModel::factory()->create();
        $conversation = $this->aConversation($ana, $luis);
        $channel = 'forum.conversation.'.$conversation->id()->value;
        $first = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 1, 1)[0];
        $this->realtime->forget();

        $this->conversations()->add($ana, $conversation->id(), $this->id($eva));
        self::assertContains('forum.account.'.$this->id($eva), $this->realtime->channelsOf('pulse.changed'));
        self::assertNotContains('forum.account.'.$this->id($ana), $this->realtime->channelsOf('pulse.changed'));

        $this->conversations()->leave($luis, $conversation->id());
        $this->conversations()->deleteOwn($ana, $first->id());

        self::assertSame([
            ['event' => 'entry.created', 'payload' => ['number' => 2]],
            ['event' => 'entry.created', 'payload' => ['number' => 3]],
            ['event' => 'entry.changed', 'payload' => ['number' => 1]],
        ], $this->realtime->on($channel));
    }

    public function test_reading_a_conversation_to_its_end_changes_ones_own_pulse_once(): void
    {
        $ana = UserModel::factory()->create();
        $luis = UserModel::factory()->create();
        $conversation = $this->aConversation($ana, $luis);
        $own = 'forum.account.'.$this->id($luis);
        $this->realtime->forget();

        $this->conversations()->markRead($luis, $conversation->id(), 1);
        $this->conversations()->markRead($luis, $conversation->id(), 1);

        self::assertCount(1, array_filter($this->realtime->messages, static fn (array $message): bool => $message['channel'] === $own));
    }

    public function test_with_real_time_off_nothing_is_sent_nor_queued(): void
    {
        config(['broadcasting.default' => 'null']);
        Event::fake([RealtimeBroadcast::class]);
        $this->app->bind(RealtimePublisherInterface::class, BroadcastRealtimePublisher::class);
        Queue::fake([SignalFollowersJob::class]);
        $follower = UserModel::factory()->create();
        [$discussion] = $this->aDiscussion($this->category);
        app(FollowsInterface::class)->setDiscussionLevel($this->id($follower), $discussion->id(), FollowLevel::Following, new DateTimeImmutable('2026-11-10 18:01:00'));

        $this->aReplyBy($discussion, UserModel::factory()->create());

        Event::assertNotDispatched(RealtimeBroadcast::class);
        Queue::assertNotPushed(SignalFollowersJob::class);
    }

    public function test_with_real_time_on_the_core_sends_the_message_as_it_is(): void
    {
        Event::fake([RealtimeBroadcast::class]);
        $this->app->bind(RealtimePublisherInterface::class, BroadcastRealtimePublisher::class);
        [$discussion] = $this->aDiscussion($this->category);

        $this->aReply($discussion, '2026-11-10 18:05:00');

        Event::assertDispatched(RealtimeBroadcast::class, fn (RealtimeBroadcast $message): bool => $message->channel === $this->discussionChannel($discussion)
            && $message->name === 'post.created'
            && $message->payload === ['number' => 2]);
    }

    private function aReplyBy(Discussion $discussion, UserModel $author): Post
    {
        return app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), $this->id($author), self::aBody('Respuesta'), new DateTimeImmutable('2026-11-10 18:05:00'), false);
    }

    private function discussionChannel(Discussion $discussion): string
    {
        return 'forum.discussion.'.$discussion->id()->value;
    }

    private function id(UserModel $user): string
    {
        return strtolower((string) $user->id);
    }
}
