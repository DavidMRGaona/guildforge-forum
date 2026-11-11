<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Publications\Enums\PublicationState;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use DateTimeImmutable;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Publications\PublicationFacts;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\SubjectConversationReader;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Entities\SubjectDiscussion;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeGameTableSource;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The conversation a record's page shows (/foro/fichas/<type>/<id>): the last posts of its
 * publication's discussion, read with the forum's own rules. Whatever cannot be shown answers
 * an empty conversation, never an error, so the page draws nothing.
 */
final class SubjectConversationTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $public;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        app(PublicationSourceRegistryInterface::class)->register(FakeGameTableSource::class, 'example');
        $this->public = $this->aCategoryFor(CategoryAudience::everyone(), 'mesas');
    }

    public function test_a_record_without_a_discussion_shows_nothing(): void
    {
        $this->getJson('/foro/fichas/game-table/unknown')->assertOk()->assertExactJson(['conversation' => null]);

        // Known to the forum, but never announced (not published yet)
        app(SubjectDiscussionRepositoryInterface::class)->save(new SubjectDiscussion(new PublicationKey('game-table', 'draft')));
        $this->getJson('/foro/fichas/game-table/draft')->assertOk()->assertExactJson(['conversation' => null]);

        // Not a publication type at all
        $this->getJson('/foro/fichas/Game_Table/x')->assertOk()->assertExactJson(['conversation' => null]);
    }

    public function test_a_type_without_an_active_source_shows_nothing_even_with_a_discussion(): void
    {
        [$discussion, $opening] = $this->aDiscussion($this->public, '2026-11-10 10:00:00');
        $record = new SubjectDiscussion(new PublicationKey('campaign', 'campaign-1'));
        $record->announce($discussion->id(), $opening->id(), ['presence' => PublicationFacts::PUBLISHED], true);
        app(SubjectDiscussionRepositoryInterface::class)->save($record);

        // Its module is off: answered as if it had no discussion
        $this->getJson('/foro/fichas/campaign/campaign-1')->assertOk()->assertExactJson(['conversation' => null]);

        app(PublicationSourceRegistryInterface::class)->unregisterModule('example');
        $this->aSubjectDiscussion('table-1', $this->public);
        $this->getJson('/foro/fichas/game-table/table-1')->assertOk()->assertExactJson(['conversation' => null]);
    }

    public function test_the_answer_is_never_kept_by_shared_caches(): void
    {
        $this->aSubjectDiscussion('table-1', $this->public);

        $cache = (string) $this->getJson('/foro/fichas/game-table/table-1')->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);

        $cache = (string) $this->getJson('/foro/fichas/game-table/unknown')->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cache);
    }

    public function test_it_shows_the_last_posts_without_the_opening_one(): void
    {
        [$discussion] = $this->aSubjectDiscussion('table-1', $this->public);
        for ($i = 2; $i <= 8; $i++) {
            $this->aReply($discussion, sprintf('2026-11-10 10:%02d:00', $i), text: "Mensaje {$i}");
        }

        $response = $this->getJson('/foro/fichas/game-table/table-1')->assertOk();

        $response->assertJsonPath('conversation.discussion.id', $discussion->id()->value)
            ->assertJsonPath('conversation.discussion.url', '/foro/t/'.$discussion->number().'-partida-del-viernes')
            ->assertJsonPath('conversation.discussion.isClosed', false)
            ->assertJsonPath('conversation.discussion.closedReason', null)
            ->assertJsonCount(SubjectConversationReader::LATEST, 'conversation.posts')
            ->assertJsonPath('conversation.posts.0.number', 4)
            ->assertJsonPath('conversation.posts.4.number', 8)
            ->assertJsonPath('conversation.posts.4.url', '/foro/t/'.$discussion->number().'-partida-del-viernes/8')
            ->assertJsonPath('conversation.olderCount', 2)
            ->assertJsonPath('conversation.olderNext', 2)
            ->assertJsonPath('conversation.olderUrl', '/foro/fichas/game-table/table-1?antes=4')
            ->assertJsonPath('conversation.highestNumber', 8)
            ->assertJsonPath('conversation.reply.canPost', false)
            ->assertJsonPath('conversation.readUrl', null)
            ->assertJsonPath('conversation.lastReadNumber', null);
        self::assertStringContainsString('Mensaje 8', (string) $response->json('conversation.posts.4.html'));
    }

    public function test_earlier_posts_load_in_batches_and_never_the_opening_one(): void
    {
        [$discussion] = $this->aSubjectDiscussion('table-1', $this->public);
        for ($i = 2; $i <= 20; $i++) {
            $this->aReply($discussion, sprintf('2026-11-10 10:%02d:00', $i));
        }

        $this->getJson('/foro/fichas/game-table/table-1')
            ->assertJsonPath('conversation.posts.0.number', 16)
            ->assertJsonPath('conversation.olderCount', 14)
            ->assertJsonPath('conversation.olderNext', SubjectConversationReader::EARLIER)
            ->assertJsonPath('conversation.olderUrl', '/foro/fichas/game-table/table-1?antes=16');

        $this->getJson('/foro/fichas/game-table/table-1?antes=16')
            ->assertOk()
            ->assertJsonCount(SubjectConversationReader::EARLIER, 'conversation.posts')
            ->assertJsonPath('conversation.posts.0.number', 6)
            ->assertJsonPath('conversation.posts.9.number', 15)
            ->assertJsonPath('conversation.olderCount', 4)
            ->assertJsonPath('conversation.olderNext', 4)
            ->assertJsonPath('conversation.olderUrl', '/foro/fichas/game-table/table-1?antes=6');

        $this->getJson('/foro/fichas/game-table/table-1?antes=6')
            ->assertJsonCount(4, 'conversation.posts')
            ->assertJsonPath('conversation.posts.0.number', 2)
            ->assertJsonPath('conversation.olderCount', 0)
            ->assertJsonPath('conversation.olderUrl', null);

        // A number that is not one asks for the latest posts
        $this->getJson('/foro/fichas/game-table/table-1?antes=cero')
            ->assertJsonPath('conversation.posts.0.number', 16);
    }

    public function test_a_discussion_with_only_its_opening_post_still_shows_the_block(): void
    {
        $this->aSubjectDiscussion('table-1', $this->public);

        $this->getJson('/foro/fichas/game-table/table-1')
            ->assertOk()
            ->assertJsonCount(0, 'conversation.posts')
            ->assertJsonPath('conversation.olderCount', 0)
            ->assertJsonPath('conversation.olderUrl', null);
    }

    public function test_what_the_reader_may_not_read_shows_nothing(): void
    {
        $private = $this->aCategoryFor(CategoryAudience::signedIn(), 'privada', position: 1);
        $this->aSubjectDiscussion('private-1', $private);
        [$hidden] = $this->aSubjectDiscussion('hidden-1', $this->public);
        $hidden->hide();
        app(ForumWriter::class)->saveDiscussion($hidden);

        $this->getJson('/foro/fichas/game-table/private-1')->assertExactJson(['conversation' => null]);
        $this->getJson('/foro/fichas/game-table/hidden-1')->assertExactJson(['conversation' => null]);

        $this->actingAs($this->anAccount())
            ->getJson('/foro/fichas/game-table/private-1')
            ->assertJsonPath('conversation.reply.canPost', true);
        $this->getJson('/foro/fichas/game-table/hidden-1')->assertExactJson(['conversation' => null]);

        $this->actingAs($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM))
            ->getJson('/foro/fichas/game-table/hidden-1')
            ->assertJsonPath('conversation.discussion.id', $hidden->id()->value);
    }

    public function test_a_signed_in_reader_gets_the_reply_box_and_their_read_mark(): void
    {
        [$discussion] = $this->aSubjectDiscussion('table-1', $this->public);
        $this->aReply($discussion, '2026-11-10 10:02:00');
        $this->aReply($discussion, '2026-11-10 10:03:00');
        $reader = $this->anAccount();
        app(DiscussionReadsInterface::class)->advance((string) $reader->id, $discussion->id(), 2, new DateTimeImmutable('2026-11-10 11:00:00'));

        $this->actingAs($reader)
            ->getJson('/foro/fichas/game-table/table-1')
            ->assertJsonPath('conversation.reply.canPost', true)
            ->assertJsonPath('conversation.reply.postUrl', route('forum.posts.store', ['discussion' => $discussion->id()->value]))
            ->assertJsonPath('conversation.reply.mentionsUrl', route('forum.mentions.index', ['discussion' => $discussion->id()->value]))
            ->assertJsonPath('conversation.reply.uploadUrl', null)
            ->assertJsonPath('conversation.readUrl', route('forum.discussions.read', ['discussion' => $discussion->id()->value]))
            ->assertJsonPath('conversation.lastReadNumber', 2);
    }

    public function test_a_closed_discussion_says_why(): void
    {
        [$cancelled] = $this->aSubjectDiscussion('cancelled-1', $this->public, PublicationState::Cancelled);
        $cancelled->closeBySystem();
        app(ForumWriter::class)->saveDiscussion($cancelled);

        [$moderated] = $this->aSubjectDiscussion('moderated-1', $this->public);
        $moderated->close();
        app(ForumWriter::class)->saveDiscussion($moderated);

        $this->actingAs($this->anAccount());
        $this->getJson('/foro/fichas/game-table/cancelled-1')
            ->assertJsonPath('conversation.discussion.isClosed', true)
            ->assertJsonPath('conversation.discussion.closedReason', 'cancelled')
            ->assertJsonPath('conversation.reply.canPost', false);
        $this->getJson('/foro/fichas/game-table/moderated-1')
            ->assertJsonPath('conversation.discussion.closedReason', 'moderation');
    }

    /**
     * A discussion opened for a publication, as the forum opens them: a first post, and the
     * record that ties them together.
     *
     * @return array{Discussion, Post}
     */
    private function aSubjectDiscussion(string $id, Category $category, PublicationState $state = PublicationState::Active): array
    {
        [$discussion, $opening] = $this->aDiscussion($category, '2026-11-10 10:00:00', title: 'Partida del viernes');
        $facts = new PublicationFacts(PublicationFacts::PUBLISHED, $state, null, false, true, 'Partida del viernes', 'opening');

        $record = new SubjectDiscussion(new PublicationKey('game-table', $id));
        $record->announce($discussion->id(), $opening->id(), $facts->toArray(), $facts->isLive());
        app(SubjectDiscussionRepositoryInterface::class)->save($record);

        return [$discussion, $opening];
    }
}
