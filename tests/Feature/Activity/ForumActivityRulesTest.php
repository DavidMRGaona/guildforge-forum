<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Activity;

use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Domain\Activity\ActivityRole;
use App\Domain\Activity\Events\ActivityRecorded;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Activity\DiscussionActivitySource;
use Modules\Forum\Infrastructure\Activity\PostActivitySource;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What the forum reports to the activity contract (forum#151): only what any signed-in member may
 * read, for the person who wrote it, and published again whenever that may have changed.
 */
final class ForumActivityRulesTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string POST = 'forum.post';

    private const string DISCUSSION = 'forum.discussion';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private UserModel $ana;

    private UserModel $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->ana = UserModel::factory()->create();
        $this->moderator = $this->anAccount('forum:forum.moderate');
    }

    public function test_a_visible_post_counts_for_its_author_when_it_was_published(): void
    {
        [$discussion] = $this->aDiscussion($this->general, '2026-11-10 18:00:00', $this->anaId());
        $reply = $this->replyBy($discussion, $this->anaId(), '2026-11-11 09:30:00');

        $facts = $this->postFacts($reply);

        $this->assertCount(1, $facts);
        $this->assertSame($this->anaId(), $facts[0]->userId);
        $this->assertSame(ActivityRole::Author, $facts[0]->role);
        $this->assertSame('2026-11-11 09:30:00', $facts[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertNull($facts[0]->placement);
        $this->assertSame([], $facts[0]->attributes);
    }

    public function test_the_opening_post_counts_as_a_post_and_its_discussion_for_whoever_opened_it(): void
    {
        [$discussion, $opening] = $this->aDiscussion($this->general, '2026-11-10 18:00:00', $this->anaId());

        $this->assertSame([$this->anaId()], $this->authorsOf($this->postFacts($opening)));

        $facts = $this->discussionFacts($discussion);
        $this->assertSame([$this->anaId()], $this->authorsOf($facts));
        $this->assertSame(ActivityRole::Author, $facts[0]->role);
        $this->assertSame('2026-11-10 18:00:00', $facts[0]->occurredAt->format('Y-m-d H:i:s'));
    }

    public function test_categories_every_signed_in_member_reads_count_and_narrower_ones_do_not(): void
    {
        $accounts = $this->aCategoryFor(CategoryAudience::signedIn(), 'cuentas');
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        $roles = $this->aCategoryFor(CategoryAudience::roles([(string) Str::uuid()]), 'junta');
        $under = $this->aCategoryFor(CategoryAudience::signedIn(), 'bajo-socios', parent: $members);
        $open = $this->aCategoryFor(CategoryAudience::everyone(), 'bajo-general', parent: $this->general);

        foreach ([$accounts, $open] as $counted) {
            [$discussion, $post] = $this->aDiscussion($counted, authorId: $this->anaId());
            $this->assertNotSame([], $this->postFacts($post), $counted->slug()->value);
            $this->assertNotSame([], $this->discussionFacts($discussion), $counted->slug()->value);
        }

        foreach ([$members, $roles, $under] as $restricted) {
            [$discussion, $post] = $this->aDiscussion($restricted, authorId: $this->anaId());
            $this->assertSame([], $this->postFacts($post), $restricted->slug()->value);
            $this->assertSame([], $this->discussionFacts($discussion), $restricted->slug()->value);
        }
    }

    public function test_nothing_counts_while_the_forum_is_a_preview(): void
    {
        [$discussion, $post] = $this->aDiscussion($this->general, authorId: $this->anaId());
        config(['modules.settings.forum.access' => 'preview']);

        $this->assertSame([], $this->postFacts($post));
        $this->assertSame([], $this->discussionFacts($discussion));
        $this->assertSame([], app(PostActivitySource::class)->history(null, 10)->subjects);
        $this->assertSame([], app(DiscussionActivitySource::class)->history(null, 10)->subjects);
    }

    public function test_a_post_pending_approval_counts_once_approved_and_approving_publishes_it(): void
    {
        [$discussion] = $this->aDiscussion($this->general, authorId: $this->anaId());
        $pending = $this->replyBy($discussion, $this->anaId(), '2026-11-11 09:30:00', needsApproval: true);
        $this->assertSame([], $this->postFacts($pending));

        $this->fakeActivity();
        app(ForumModeration::class)->approve($this->moderator, $pending->id());

        $this->assertPublished(self::POST, $pending->id()->value);
        $this->assertSame([$this->anaId()], $this->authorsOf($this->postFacts($pending)));
    }

    public function test_a_discussion_awaiting_approval_counts_once_approved(): void
    {
        [$discussion, $opening] = $this->aDiscussion($this->general, authorId: $this->anaId(), needsApproval: true);
        $this->assertSame([], $this->discussionFacts($discussion));
        $this->assertSame([], $this->postFacts($opening));

        $this->fakeActivity();
        app(ForumModeration::class)->approve($this->moderator, $opening->id());

        $this->assertPublished(self::DISCUSSION, $discussion->id()->value);
        $this->assertPublished(self::POST, $opening->id()->value);
        $this->assertNotSame([], $this->discussionFacts($discussion));
    }

    public function test_writing_publishes_the_post_and_its_discussion(): void
    {
        $this->fakeActivity();

        [$discussion, $opening] = $this->aDiscussion($this->general, authorId: $this->anaId());
        $reply = $this->replyBy($discussion, $this->anaId(), '2026-11-11 09:30:00');

        $this->assertPublished(self::DISCUSSION, $discussion->id()->value);
        $this->assertPublished(self::POST, $opening->id()->value);
        $this->assertPublished(self::POST, $reply->id()->value);
    }

    public function test_hiding_a_post_stops_it_counting_and_showing_it_again_brings_it_back(): void
    {
        [$discussion] = $this->aDiscussion($this->general, authorId: $this->anaId());
        $reply = $this->replyBy($discussion, $this->anaId(), '2026-11-11 09:30:00');

        $this->fakeActivity();
        app(ForumModeration::class)->hidePost($this->moderator, $reply->id(), 'Fuera de tema');
        $this->assertPublished(self::POST, $reply->id()->value);
        $this->assertSame([], $this->postFacts($reply));

        $this->fakeActivity();
        app(ForumModeration::class)->showPost($this->moderator, $reply->id());
        $this->assertPublished(self::POST, $reply->id()->value);
        $this->assertNotSame([], $this->postFacts($reply));
    }

    public function test_a_post_its_author_deleted_stops_counting(): void
    {
        [$discussion] = $this->aDiscussion($this->general, authorId: $this->anaId());
        $reply = $this->replyBy($discussion, $this->anaId(), '2026-11-11 09:30:00');

        $this->fakeActivity();
        app(ForumWriter::class)->deletePostByAuthor($reply->id(), new DateTimeImmutable('2026-11-12 10:00:00'));

        $this->assertPublished(self::POST, $reply->id()->value);
        $this->assertSame([], $this->postFacts($reply));
    }

    public function test_a_discussion_whose_every_post_was_hidden_stops_counting(): void
    {
        [$discussion, $opening] = $this->aDiscussion($this->general, authorId: $this->anaId());

        $this->fakeActivity();
        app(ForumModeration::class)->hidePost($this->moderator, $opening->id());

        $this->assertPublished(self::DISCUSSION, $discussion->id()->value);
        $this->assertSame([], $this->discussionFacts($discussion));
    }

    public function test_hiding_a_discussion_publishes_it_with_every_post_and_none_counts(): void
    {
        [$discussion, $opening] = $this->aDiscussion($this->general, authorId: $this->anaId());
        $reply = $this->replyBy($discussion, $this->anaId(), '2026-11-11 09:30:00');

        $this->fakeActivity();
        app(ForumModeration::class)->hide($this->moderator, $discussion->id(), 'Spam');

        $this->assertPublished(self::DISCUSSION, $discussion->id()->value);
        $this->assertPublished(self::POST, $opening->id()->value);
        $this->assertPublished(self::POST, $reply->id()->value);
        $this->assertSame([], $this->discussionFacts($discussion));
        $this->assertSame([], $this->postFacts($reply));

        $this->fakeActivity();
        app(ForumModeration::class)->show($this->moderator, $discussion->id());

        $this->assertPublished(self::POST, $reply->id()->value);
        $this->assertNotSame([], $this->postFacts($reply));
        $this->assertNotSame([], $this->discussionFacts($discussion));
    }

    public function test_moving_a_discussion_to_a_restricted_category_publishes_it_and_it_stops_counting(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        [$discussion, $opening] = $this->aDiscussion($this->general, authorId: $this->anaId());

        $this->fakeActivity();
        app(ForumModeration::class)->move($this->moderator, $discussion->id(), $members->id());

        $this->assertPublished(self::DISCUSSION, $discussion->id()->value);
        $this->assertPublished(self::POST, $opening->id()->value);
        $this->assertSame([], $this->discussionFacts($discussion));
        $this->assertSame([], $this->postFacts($opening));
    }

    public function test_deleting_a_discussion_publishes_it_with_every_post(): void
    {
        [$discussion, $opening] = $this->aDiscussion($this->general, authorId: $this->anaId());
        $reply = $this->replyBy($discussion, $this->anaId(), '2026-11-11 09:30:00');

        $this->fakeActivity();
        app(ForumModeration::class)->delete($this->moderator, $discussion->id());

        $this->assertPublished(self::DISCUSSION, $discussion->id()->value);
        $this->assertPublished(self::POST, $opening->id()->value);
        $this->assertPublished(self::POST, $reply->id()->value);
        $this->assertSame([], $this->discussionFacts($discussion));
        $this->assertSame([], $this->postFacts($reply));
    }

    public function test_merging_publishes_the_discussion_that_goes_and_the_posts_that_move(): void
    {
        [$absorbed, $moved] = $this->aDiscussion($this->general, '2026-11-10 18:00:00', $this->anaId());
        [$into] = $this->aDiscussion($this->general, '2026-11-10 19:00:00', $this->anaId(), title: 'Otra sesión');

        $this->fakeActivity();
        app(ForumModeration::class)->merge($this->moderator, $absorbed->id(), $into->id());

        $this->assertPublished(self::DISCUSSION, $absorbed->id()->value);
        $this->assertPublished(self::POST, $moved->id()->value);
        $this->assertSame([], $this->discussionFacts($absorbed));
        $this->assertNotSame([], $this->postFacts($moved));
    }

    public function test_narrowing_who_reads_a_category_publishes_what_it_holds_in_the_queue(): void
    {
        $sub = $this->aCategoryFor(CategoryAudience::everyone(), 'sub', parent: $this->general);
        [$discussion, $opening] = $this->aDiscussion($this->general, authorId: $this->anaId());
        [$inSub, $subOpening] = $this->aDiscussion($sub, authorId: $this->anaId());

        $this->fakeActivity();
        // Its subcategory has to be as narrow first
        $sub->changeAccess(CategoryAudience::associationMembers(), $sub->writeAudience(), $sub->moderators());
        app(CategoryManager::class)->save($sub);
        $this->general->changeAccess(CategoryAudience::associationMembers(), $this->general->writeAudience(), $this->general->moderators());
        app(CategoryManager::class)->save($this->general);

        foreach ([[self::DISCUSSION, $discussion->id()->value], [self::POST, $opening->id()->value], [self::DISCUSSION, $inSub->id()->value], [self::POST, $subOpening->id()->value]] as [$type, $id]) {
            $this->assertPublished($type, $id);
        }
        $this->assertSame([], $this->postFacts($opening));
        $this->assertSame([], $this->discussionFacts($inSub));
    }

    public function test_renaming_a_category_publishes_nothing(): void
    {
        $this->aDiscussion($this->general, authorId: $this->anaId());

        $this->fakeActivity();
        $this->general->rename('General y más', $this->general->slug(), null);
        app(CategoryManager::class)->save($this->general);

        Event::assertNotDispatched(ActivityRecorded::class);
    }

    public function test_a_post_as_a_personal_character_counts_for_whoever_wrote_it(): void
    {
        [$discussion] = $this->aDiscussion($this->general, authorId: $this->anaId());
        $character = $this->aCharacterOf($this->anaId());
        $post = $this->replyBy($discussion, $this->anaId(), '2026-11-11 09:30:00', characterId: $character->id());

        $this->assertSame([$this->anaId()], $this->authorsOf($this->postFacts($post)));
    }

    public function test_a_post_as_a_shared_character_hides_its_writer_and_never_counts(): void
    {
        $space = $this->aCategoryFor(CategoryAudience::everyone(), 'espacio');
        [$discussion] = $this->aDiscussion($space, authorId: $this->anaId());
        $npc = $this->aSharedCharacterIn($space);
        $post = $this->replyBy($discussion, $this->anaId(), '2026-11-11 09:30:00', characterId: $npc->id());

        $this->assertSame([], $this->postFacts($post));
        $this->assertNotContains($post->id()->value, $this->historyIds(app(PostActivitySource::class)));
    }

    public function test_deleting_a_shared_character_publishes_what_was_written_as_it(): void
    {
        $space = $this->aCategoryFor(CategoryAudience::everyone(), 'espacio');
        [$discussion] = $this->aDiscussion($space, authorId: $this->anaId());
        $npc = $this->aSharedCharacterIn($space);
        $post = $this->replyBy($discussion, $this->anaId(), '2026-11-11 09:30:00', characterId: $npc->id());

        $this->fakeActivity();
        app(CharacterRepositoryInterface::class)->delete($npc->id());

        $this->assertPublished(self::POST, $post->id()->value);
    }

    public function test_what_the_system_account_or_nobody_wrote_never_counts(): void
    {
        $system = app(SystemAccountInterface::class)->id();
        [$byTheSite, $siteOpening] = $this->aDiscussion($this->general, authorId: $system);
        // Without an author: an account erased since, or never one
        [$byNobody, $nobodyOpening] = $this->aDiscussion($this->general);

        $this->assertSame([], $this->postFacts($siteOpening));
        $this->assertSame([], $this->discussionFacts($byTheSite));
        $this->assertSame([], $this->postFacts($nobodyOpening));
        $this->assertSame([], $this->discussionFacts($byNobody));
    }

    public function test_the_history_holds_only_what_counts_and_is_the_same_for_anybody(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        [$counted, $countedOpening] = $this->aDiscussion($this->general, authorId: $this->anaId());
        [$restricted, $restrictedOpening] = $this->aDiscussion($members, authorId: $this->anaId());
        $pending = $this->replyBy($counted, $this->anaId(), '2026-11-11 09:30:00', needsApproval: true);

        $posts = $this->historyIds(app(PostActivitySource::class));
        $discussions = $this->historyIds(app(DiscussionActivitySource::class));

        $this->assertSame([$countedOpening->id()->value], $posts);
        $this->assertSame([$counted->id()->value], $discussions);
        $this->assertNotContains($restrictedOpening->id()->value, $posts);
        $this->assertNotContains($restricted->id()->value, $discussions);
        $this->assertNotContains($pending->id()->value, $posts);

        // A moderator signed in sees more on the pages, never here
        $this->actingAs($this->moderator);
        $this->assertSame($posts, $this->historyIds(app(PostActivitySource::class)));
        $this->assertSame($discussions, $this->historyIds(app(DiscussionActivitySource::class)));
        $this->assertSame([], $this->postFacts($restrictedOpening));
    }

    public function test_an_unreadable_cursor_throws(): void
    {
        foreach ([app(PostActivitySource::class), app(DiscussionActivitySource::class)] as $source) {
            foreach (['nonsense', base64_encode('forum.post:not-an-id'), ''] as $cursor) {
                try {
                    $source->history($cursor, 10);
                    $this->fail("{$source->type()} read the cursor «{$cursor}».");
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        // One source's cursor is not the other's
        $this->aDiscussion($this->general, authorId: $this->anaId());
        $this->aDiscussion($this->general, authorId: $this->anaId());
        $cursor = app(PostActivitySource::class)->history(null, 1)->nextCursor;
        $this->assertNotNull($cursor);
        $this->expectException(InvalidArgumentException::class);
        app(DiscussionActivitySource::class)->history($cursor, 1);
    }

    public function test_describe_gives_title_and_address_only_to_whoever_may_read_it(): void
    {
        $accounts = $this->aCategoryFor(CategoryAudience::signedIn(), 'cuentas');
        [$discussion] = $this->aDiscussion($accounts, authorId: $this->anaId(), title: 'Partida del sábado');
        $reply = $this->replyBy($discussion, $this->anaId(), '2026-11-11 09:30:00');

        $this->assertSame([], app(PostActivitySource::class)->describe([$reply->id()->value], null));
        $this->assertSame([], app(DiscussionActivitySource::class)->describe([$discussion->id()->value], null));

        $post = app(PostActivitySource::class)->describe([$reply->id()->value], $this->ana)[$reply->id()->value] ?? null;
        $this->assertNotNull($post);
        $this->assertStringContainsString('Partida del sábado', $post->title);
        $this->assertStringStartsWith('/', (string) $post->url);

        $topic = app(DiscussionActivitySource::class)->describe([strtoupper($discussion->id()->value)], $this->ana)[$discussion->id()->value] ?? null;
        $this->assertNotNull($topic);
        $this->assertSame('Partida del sábado', $topic->title);
        $this->assertStringStartsWith('/', (string) $topic->url);

        app(ForumModeration::class)->hidePost($this->moderator, $reply->id());
        $this->assertSame([], app(PostActivitySource::class)->describe([$reply->id()->value], $this->ana));
    }

    public function test_ids_are_read_in_lower_case_and_anything_else_has_no_facts(): void
    {
        [$discussion, $opening] = $this->aDiscussion($this->general, authorId: $this->anaId());

        $this->assertNotSame([], app(PostActivitySource::class)->facts(strtoupper($opening->id()->value)));
        $this->assertNotSame([], app(DiscussionActivitySource::class)->facts(strtoupper($discussion->id()->value)));
        $this->assertSame([], app(PostActivitySource::class)->facts('not-a-uuid'));
        $this->assertSame([], app(DiscussionActivitySource::class)->facts('42'));
    }

    private function anaId(): string
    {
        return strtolower((string) $this->ana->id);
    }

    private function replyBy(Discussion $discussion, string $authorId, string $at, bool $needsApproval = false, ?CharacterId $characterId = null): Post
    {
        return app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), $authorId, self::aBody('Respuesta'), new DateTimeImmutable($at), $needsApproval, $characterId);
    }

    /**
     * @return list<ActivityFactDTO>
     */
    private function postFacts(Post $post): array
    {
        return app(PostActivitySource::class)->facts($post->id()->value);
    }

    /**
     * @return list<ActivityFactDTO>
     */
    private function discussionFacts(Discussion $discussion): array
    {
        return app(DiscussionActivitySource::class)->facts($discussion->id()->value);
    }

    /**
     * @param  list<ActivityFactDTO>  $facts
     * @return list<string>
     */
    private function authorsOf(array $facts): array
    {
        return array_map(static fn (ActivityFactDTO $fact): string => $fact->userId, $facts);
    }

    /**
     * @return list<string>
     */
    private function historyIds(PostActivitySource|DiscussionActivitySource $source): array
    {
        $ids = [];
        $cursor = null;
        do {
            $page = $source->history($cursor, 2);
            foreach ($page->subjects as $subject) {
                $ids[] = $subject->subjectId;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return $ids;
    }

    /**
     * Fakes the announcements from now on. The core's publisher is scoped and keeps the
     * dispatcher it was built with, so the one built before is forgotten.
     */
    private function fakeActivity(): void
    {
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetScopedInstances();
    }

    private function assertPublished(string $type, string $id): void
    {
        Event::assertDispatched(
            ActivityRecorded::class,
            static fn (ActivityRecorded $event): bool => $event->sourceType === $type && $event->subjectId === $id,
        );
    }
}
