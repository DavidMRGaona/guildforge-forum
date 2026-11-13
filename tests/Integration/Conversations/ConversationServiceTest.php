<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Conversations;

use App\Application\Services\UserServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Conversations\BlockService;
use Modules\Forum\Application\Conversations\ConversationAccess;
use Modules\Forum\Application\Queries\ConversationListQueryInterface;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\ConversationRules;
use Modules\Forum\Domain\Entities\Conversation;
use Modules\Forum\Domain\Enums\ConversationEntryKind;
use Modules\Forum\Domain\Exceptions\ConversationNotFoundException;
use Modules\Forum\Domain\Exceptions\ConversationRefusedException;
use Modules\Forum\Domain\Exceptions\InvalidConversationException;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ConversationMessageModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ConversationModel;
use Modules\Forum\Tests\Support\BuildsConversations;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The rules of private conversations (forum#18): who takes part, who reads, who writes, the
 * limit per day, blocks, adding people, leaving and deleting one's own messages.
 */
final class ConversationServiceTest extends ModuleTestCase
{
    use BuildsConversations;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MODERATE = 'forum:forum.moderate';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $luis;

    private UserModel $eva;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        CarbonImmutable::setTestNow('2026-11-20 10:00:00');
        $this->ana = $this->anAccount();
        $this->luis = $this->anAccount();
        $this->eva = $this->anAccount();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_starting_puts_everyone_in_with_the_first_message_unread_for_the_others(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis, $this->eva);

        foreach ([$this->ana, $this->luis, $this->eva] as $user) {
            $this->assertSame($conversation->id()->value, $this->conversations()->readable($user, $conversation->id())->id()->value);
        }

        $entries = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 1, 10);
        $this->assertCount(1, $entries);
        $this->assertSame(strtolower($this->ana->id), $entries[0]->authorId());
        $this->assertSame('Hola, ¿quedamos el viernes?', $entries[0]->body()->plainText);

        $list = app(ConversationListQueryInterface::class);
        $this->assertSame(0, $list->unreadCount($this->ana->id));
        $this->assertSame(1, $list->unreadCount($this->luis->id));
        $this->assertSame(1, $list->page($this->eva->id, 1, 20)['rows'][0]->unread);
    }

    public function test_nobody_outside_the_conversation_reads_it_not_even_moderation_or_the_administration(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        $outsiders = [$this->eva, $this->anAccount(self::MODERATE), $this->anAdmin(), null];

        foreach ($outsiders as $outsider) {
            $this->assertFalse(app(ConversationAccess::class)->canRead($outsider, $conversation->id()));
            try {
                $this->conversations()->readable($outsider, $conversation->id());
                $this->fail('An outsider read a private conversation');
            } catch (ConversationNotFoundException) {
                // As if it did not exist
            }
        }

        $this->assertSame(0, app(ConversationListQueryInterface::class)->page($this->eva->id, 1, 20)['total']);
    }

    public function test_reading_fails_closed_when_the_participants_cannot_be_read(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        DB::statement('ALTER TABLE forum_conversation_participants RENAME TO forum_conversation_participants_gone');

        try {
            $this->assertFalse(app(ConversationAccess::class)->canRead($this->ana, $conversation->id()));
        } finally {
            DB::statement('ALTER TABLE forum_conversation_participants_gone RENAME TO forum_conversation_participants');
        }
    }

    public function test_a_conversation_has_from_two_to_ten_people(): void
    {
        $this->assertRefused(ConversationRefusedException::PARTICIPANTS, fn (): Conversation => $this->conversations()->start($this->ana, 'Solo', [], $this->messageDocument()));

        $nine = array_map(fn (): string => $this->anAccount()->id, range(1, ConversationRules::MAX_PARTICIPANTS - 1));
        $this->assertCount(10, $this->participantsOf($this->conversations()->start($this->ana, 'Diez', $nine, $this->messageDocument())));

        $ten = [...$nine, $this->anAccount()->id];
        $this->assertRefused(ConversationRefusedException::PARTICIPANTS, fn (): Conversation => $this->conversations()->start($this->ana, 'Once', $ten, $this->messageDocument()));
    }

    public function test_only_active_accounts_not_suspended_take_part(): void
    {
        $suspended = $this->anAccount();
        app(SuspensionService::class)->suspend($this->anAdmin(), $suspended->id, 'Spam', null);
        $deactivated = $this->anAccount();
        $deactivated->delete();
        $anonymized = $this->anAccount();
        app(UserServiceInterface::class)->anonymize($anonymized->id);
        $system = app(SystemAccountInterface::class)->id();

        foreach ([$suspended->id, $deactivated->id, $anonymized->id, $system, $this->ana->id, 'not-an-id', '7c7f1a7e-0000-4000-8000-000000000000'] as $id) {
            $this->assertRefused(ConversationRefusedException::UNAVAILABLE_PEOPLE, fn (): Conversation => $this->conversations()->start($this->ana, 'Con alguien', [$id], $this->messageDocument()));
        }

        $this->assertSame(0, ConversationModel::query()->count());
    }

    public function test_whoever_is_suspended_reads_but_neither_starts_nor_writes_nor_adds(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        app(SuspensionService::class)->suspend($this->anAdmin(), $this->luis->id, 'Spam', null);
        app()->forgetScopedInstances();

        $this->assertSame($conversation->id()->value, $this->conversations()->readable($this->luis, $conversation->id())->id()->value);
        $this->assertSame(ConversationRefusedException::BARRED, app(ConversationAccess::class)->writeRefusal($this->luis, $conversation));
        $this->assertRefused(ConversationRefusedException::BARRED, fn () => $this->conversations()->reply($this->luis, $conversation->id(), $this->messageDocument()));
        $this->assertRefused(ConversationRefusedException::BARRED, fn () => $this->conversations()->start($this->luis, 'Otra', [$this->eva->id], $this->messageDocument()));
        $this->assertRefused(ConversationRefusedException::BARRED, fn () => $this->conversations()->add($this->luis, $conversation->id(), $this->eva->id));
        // And nobody adds them elsewhere
        $other = $this->aConversation($this->ana, $this->eva);
        $this->assertRefused(ConversationRefusedException::UNAVAILABLE_PEOPLE, fn () => $this->conversations()->add($this->ana, $other->id(), $this->luis->id));
    }

    public function test_five_new_conversations_a_day_and_replying_does_not_count(): void
    {
        for ($i = 0; $i < ConversationRules::STARTS_PER_DAY; $i++) {
            $last = $this->aConversation($this->ana, $this->luis);
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
        }
        $this->assertSame(0, $this->conversations()->startsLeft($this->ana));

        $this->assertRefused(ConversationRefusedException::DAILY_LIMIT, fn () => $this->aConversation($this->ana, $this->eva));
        $this->conversations()->reply($this->ana, $last->id(), $this->messageDocument('Respondo, y eso no cuenta'));
        // Others still start theirs
        $this->aConversation($this->luis, $this->ana);

        // Leaving gives no start back
        $this->conversations()->leave($this->ana, $last->id());
        $this->assertRefused(ConversationRefusedException::DAILY_LIMIT, fn () => $this->aConversation($this->ana, $this->eva));

        // A day after the first one, one more
        CarbonImmutable::setTestNow('2026-11-21 10:00:01');
        $this->assertSame(1, $this->conversations()->startsLeft($this->ana));
        $this->aConversation($this->ana, $this->eva);
        $this->assertRefused(ConversationRefusedException::DAILY_LIMIT, fn () => $this->aConversation($this->ana, $this->eva));
    }

    public function test_a_block_keeps_the_blocked_person_from_starting_with_adding_or_writing_to_whoever_blocked_them(): void
    {
        $shared = $this->aConversation($this->ana, $this->luis);
        app(BlockService::class)->block($this->ana, $this->luis->id);

        // The conversation they had stays, for both
        $this->assertSame($shared->id()->value, $this->conversations()->readable($this->luis, $shared->id())->id()->value);
        // Luis can no longer write in it, in the same words as anything else he cannot do
        $this->assertRefused(ConversationRefusedException::CANNOT_WRITE, fn () => $this->conversations()->reply($this->luis, $shared->id(), $this->messageDocument()));
        // Ana still writes
        $this->conversations()->reply($this->ana, $shared->id(), $this->messageDocument('Sigo aquí'));

        // Nor starts one with her, alone or with others
        $this->assertRefused(ConversationRefusedException::UNAVAILABLE_PEOPLE, fn () => $this->aConversation($this->luis, $this->ana));
        $this->assertRefused(ConversationRefusedException::UNAVAILABLE_PEOPLE, fn () => $this->aConversation($this->luis, $this->eva, $this->ana));
        // Nor adds her
        $withEva = $this->aConversation($this->luis, $this->eva);
        $this->assertRefused(ConversationRefusedException::UNAVAILABLE_PEOPLE, fn () => $this->conversations()->add($this->luis, $withEva->id(), $this->ana->id));

        // Nor adds anyone where she is: the note of who was added would reach her
        $this->assertRefused(ConversationRefusedException::CANNOT_WRITE, fn () => $this->conversations()->add($this->luis, $shared->id(), $this->eva->id));

        // Someone else may add her; then Luis cannot write there either
        $this->conversations()->add($this->eva, $withEva->id(), $this->ana->id);
        $this->assertRefused(ConversationRefusedException::CANNOT_WRITE, fn () => $this->conversations()->reply($this->luis, $withEva->id(), $this->messageDocument()));
        $this->conversations()->reply($this->eva, $withEva->id(), $this->messageDocument('Eva escribe'));

        // Unblocking gives it all back
        app(BlockService::class)->unblock($this->ana, $this->luis->id);
        $this->conversations()->reply($this->luis, $shared->id(), $this->messageDocument('De nuevo'));
        $this->conversations()->reply($this->luis, $withEva->id(), $this->messageDocument('De nuevo'));
    }

    public function test_a_block_is_one_way_and_silent(): void
    {
        app(BlockService::class)->block($this->ana, $this->luis->id);

        $this->assertSame([], app(BlockService::class)->blockedBy($this->luis));
        $this->assertSame([strtolower($this->luis->id)], array_column(app(BlockService::class)->blockedBy($this->ana), 'id'));
        // Whoever blocked may still start one with the person
        $this->aConversation($this->ana, $this->luis);
    }

    public function test_nobody_blocks_themself_the_system_account_or_an_account_that_does_not_exist(): void
    {
        foreach ([$this->ana->id, app(SystemAccountInterface::class)->id(), '7c7f1a7e-0000-4000-8000-000000000000', 'nope'] as $id) {
            $this->assertRefused(ConversationRefusedException::NOT_ALLOWED, fn () => app(BlockService::class)->block($this->ana, $id));
        }
    }

    public function test_anyone_inside_adds_people_until_ten_and_they_read_it_from_the_start(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        $this->conversations()->reply($this->luis, $conversation->id(), $this->messageDocument('Antes de Eva'));

        $this->conversations()->add($this->luis, $conversation->id(), $this->eva->id);

        $readable = $this->conversations()->readable($this->eva, $conversation->id());
        $this->assertSame(3, $readable->lastNumber());
        $this->assertSame('Antes de Eva', app(ConversationRepositoryInterface::class)->entries($conversation->id(), 1, 3)[1]->body()->plainText);
        // What was there when she arrived is not unread for her
        $this->assertSame(0, app(ConversationListQueryInterface::class)->unreadCount($this->eva->id));
        $note = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 3, 3)[0];
        $this->assertSame([ConversationEntryKind::Added, strtolower($this->luis->id), strtolower($this->eva->id)], [$note->kind(), $note->authorId(), $note->subjectUserId()]);

        $this->assertRefused(ConversationRefusedException::ALREADY_IN, fn () => $this->conversations()->add($this->ana, $conversation->id(), $this->eva->id));
        for ($i = 0; $i < ConversationRules::MAX_PARTICIPANTS - 3; $i++) {
            $this->conversations()->add($this->ana, $conversation->id(), $this->anAccount()->id);
        }
        $this->assertCount(10, $this->participantsOf($conversation));
        $this->assertRefused(ConversationRefusedException::FULL, fn () => $this->conversations()->add($this->ana, $conversation->id(), $this->anAccount()->id));
        // Whoever is not inside adds nobody
        $this->expectException(ConversationNotFoundException::class);
        $this->conversations()->add($this->anAccount(), $conversation->id(), $this->anAccount()->id);
    }

    public function test_whoever_leaves_reads_nothing_more_and_the_last_one_out_takes_the_conversation_with_them(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis, $this->eva);

        $this->conversations()->leave($this->luis, $conversation->id());
        $this->assertFalse(app(ConversationAccess::class)->canRead($this->luis, $conversation->id()));
        $this->assertSame(0, app(ConversationListQueryInterface::class)->page($this->luis->id, 1, 20)['total']);
        $note = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 2, 2)[0];
        $this->assertSame([ConversationEntryKind::Left, strtolower($this->luis->id)], [$note->kind(), $note->authorId()]);
        // A note does not make it unread
        $this->assertSame(0, app(ConversationListQueryInterface::class)->unreadCount($this->ana->id));

        $this->conversations()->leave($this->eva, $conversation->id());
        // Ana is alone: she reads, but writes to nobody until she adds someone
        $this->assertRefused(ConversationRefusedException::ALONE, fn () => $this->conversations()->reply($this->ana, $conversation->id(), $this->messageDocument()));
        $this->conversations()->add($this->ana, $conversation->id(), $this->luis->id);
        $this->conversations()->reply($this->ana, $conversation->id(), $this->messageDocument('Vuelve Luis'));

        $this->conversations()->leave($this->ana, $conversation->id());
        $this->conversations()->leave($this->luis, $conversation->id());
        $this->assertSame(0, ConversationModel::query()->count());
        $this->assertSame(0, ConversationMessageModel::query()->count());
    }

    public function test_authors_delete_their_own_messages_only(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        $first = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 1, 1)[0];

        $this->assertRefused(ConversationRefusedException::NOT_ALLOWED, fn () => $this->conversations()->deleteOwn($this->luis, $first->id()));
        try {
            $this->conversations()->deleteOwn($this->eva, $first->id());
            $this->fail('Someone outside deleted a message');
        } catch (ConversationNotFoundException) {
        }

        $this->conversations()->deleteOwn($this->ana, $first->id());
        $row = ConversationMessageModel::query()->findOrFail($first->id()->value);
        $this->assertNull($row->body);
        $this->assertNull($row->plain_text);
        $this->assertNotNull($row->deleted_at);
        $this->assertRefused(ConversationRefusedException::NOT_ALLOWED, fn () => $this->conversations()->deleteOwn($this->ana, $first->id()));
        $this->expectException(ConversationNotFoundException::class);
        $this->conversations()->deleteOwn($this->ana, ConversationMessageId::generate());
    }

    public function test_a_message_holds_text_and_formatting_only(): void
    {
        $refused = [
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => 'forum/posts/2026/11/0b3a2a4e-5d1f-4a7e-9c3b-1f2e3d4c5b6a.png']],
            ['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => ['id' => $this->luis->id]]]],
            ['type' => 'roll', 'attrs' => ['id' => '0b3a2a4e-5d1f-4a7e-9c3b-1f2e3d4c5b6a', 'expression' => '2d6']],
            ['type' => 'embed', 'attrs' => ['service' => 'youtube', 'id' => 'dQw4w9WgXcQ']],
            ['type' => 'postQuote', 'attrs' => ['postId' => '0b3a2a4e-5d1f-4a7e-9c3b-1f2e3d4c5b6a'], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Cita']]]]],
            ['type' => 'ooc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Aparte']]]]],
        ];

        foreach ($refused as $node) {
            try {
                $this->conversations()->start($this->ana, 'Con algo raro', [$this->luis->id], ['type' => 'doc', 'content' => [$node]]);
                $this->fail("A message took a {$node['type']} node");
            } catch (InvalidDocumentException) {
            }
        }

        $formatted = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Plan']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ver ', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => 'el reglamento', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.test/reglas']]]]]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Dados']]]]]]],
        ]];
        $conversation = $this->conversations()->start($this->ana, 'Con formato', [$this->luis->id], $formatted);
        $this->assertStringContainsString('el reglamento', app(ConversationRepositoryInterface::class)->entries($conversation->id(), 1, 1)[0]->body()->plainText);
    }

    public function test_a_title_is_needed_and_short(): void
    {
        $this->expectException(InvalidConversationException::class);
        $this->conversations()->start($this->ana, str_repeat('a', 121), [$this->luis->id], $this->messageDocument());
    }

    public function test_marking_read_never_goes_back_nor_past_the_end(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        $this->conversations()->reply($this->ana, $conversation->id(), $this->messageDocument('Segundo'));
        $list = app(ConversationListQueryInterface::class);
        $this->assertSame(2, $list->page($this->luis->id, 1, 20)['rows'][0]->unread);

        $this->conversations()->markRead($this->luis, $conversation->id(), 1);
        $this->assertSame(1, $list->page($this->luis->id, 1, 20)['rows'][0]->unread);
        $this->conversations()->markRead($this->luis, $conversation->id(), 99);
        $this->conversations()->markRead($this->luis, $conversation->id(), 0);
        $this->assertSame(0, $list->unreadCount($this->luis->id));

        $this->conversations()->reply($this->ana, $conversation->id(), $this->messageDocument('Tercero'));
        $this->assertSame(1, $list->unreadCount($this->luis->id));
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function assertRefused(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected refusal «{$reason}»");
        } catch (ConversationRefusedException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    /**
     * @return list<string>
     */
    private function participantsOf(Conversation $conversation): array
    {
        return array_values(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            DB::table('forum_conversation_participants')->where('conversation_id', $conversation->id()->value)->pluck('user_id')->all(),
        ));
    }

    private function anAdmin(): UserModel
    {
        return UserModel::factory()->admin()->create();
    }
}
