<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Services\UserServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Modules\Forum\Application\Conversations\BlockService;
use Modules\Forum\Application\Conversations\ConversationReportService;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\Entities\Conversation;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ConversationReportModel;
use Modules\Forum\Tests\Support\BuildsConversations;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The pages and endpoints of private conversations: only the people in one ever get anything
 * of it, whatever else they may do in the forum or the site.
 */
final class ConversationEndpointsTest extends ModuleTestCase
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

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['scout.driver' => 'null']);
        CarbonImmutable::setTestNow('2026-11-20 10:00:00');
        $this->ana = $this->anAccount();
        $this->ana->update(['name' => 'Ana Pérez', 'display_name' => null]);
        $this->luis = $this->anAccount();
        $this->luis->update(['name' => 'Luis Gómez', 'display_name' => null]);
        $this->eva = $this->anAccount();
        $this->eva->update(['name' => 'Eva Ruiz', 'display_name' => null]);
        $this->conversation = $this->aConversation($this->ana, $this->luis);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_guests_get_nothing(): void
    {
        $id = $this->conversation->id()->value;

        $this->get('/foro/conversaciones')->assertRedirect();
        $this->get("/foro/conversaciones/{$id}")->assertRedirect();
        $this->postJson("/foro/conversaciones/{$id}/mensajes", ['document' => $this->messageDocument()])->assertUnauthorized();
        $this->getJson('/foro/conversaciones/personas?q=an')->assertUnauthorized();
        $this->getJson('/foro/bloqueos')->assertUnauthorized();
    }

    public function test_nobody_outside_the_conversation_gets_anything_of_it(): void
    {
        $id = $this->conversation->id()->value;
        $message = app(ConversationRepositoryInterface::class)->entries($this->conversation->id(), 1, 1)[0]->id()->value;

        foreach ([$this->eva, $this->anAccount(self::MODERATE), UserModel::factory()->admin()->create()] as $outsider) {
            $this->actingAs($outsider);
            $this->get("/foro/conversaciones/{$id}")->assertNotFound();
            $this->postJson("/foro/conversaciones/{$id}/mensajes", ['document' => $this->messageDocument()])->assertNotFound();
            $this->postJson("/foro/conversaciones/{$id}/personas", ['account' => $outsider->id])->assertNotFound();
            $this->postJson("/foro/conversaciones/{$id}/salir")->assertNotFound();
            $this->postJson("/foro/conversaciones/{$id}/leido", ['number' => 1])->assertNotFound();
            $this->deleteJson("/foro/conversaciones/mensajes/{$message}")->assertNotFound();
            $this->postJson("/foro/conversaciones/mensajes/{$message}/denuncias", ['reason' => 'spam'])->assertNotFound();
            $this->getJson("/foro/conversaciones/personas?q=ev&conversacion={$id}")->assertOk()->assertJsonPath('people', []);
            $this->get('/foro/conversaciones')->assertInertia(fn (AssertableInertia $page) => $page->component('Forum/Conversations', false)->where('conversations', []));
        }
    }

    public function test_the_list_shows_the_persons_conversations_with_unread_messages(): void
    {
        $this->actingAs($this->luis)->get('/foro/conversaciones')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Forum/Conversations', false)
                ->has('conversations', 1)
                ->where('conversations.0.title', 'Partida del viernes')
                ->where('conversations.0.unread', 1)
                ->where('conversations.0.people', ['Ana Pérez'])
                ->where('startsLeft', 5)
                ->where('seo.robots', fn (?string $robots): bool => str_contains((string) $robots, 'noindex')));
    }

    public function test_opening_a_conversation_shows_it_and_reads_it(): void
    {
        $id = $this->conversation->id()->value;

        $this->actingAs($this->luis)->get("/foro/conversaciones/{$id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Forum/Conversation', false)
                ->where('conversation.title', 'Partida del viernes')
                ->has('participants', 2)
                ->has('entries', 1)
                ->where('entries.0.authorName', 'Ana Pérez')
                ->where('entries.0.html', '<p>Hola, ¿quedamos el viernes?</p>')
                ->where('entries.0.deleteUrl', null)
                ->where('entries.0.reportUrl', route('forum.conversations.reports.store', ['message' => app(ConversationRepositoryInterface::class)->entries($this->conversation->id(), 1, 1)[0]->id()->value]))
                ->where('write.refusal', null));

        $this->actingAs($this->luis)->get('/foro/conversaciones')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('conversations.0.unread', 0));
    }

    public function test_starting_writing_adding_and_leaving(): void
    {
        $this->actingAs($this->ana);
        $answer = $this->postJson('/foro/conversaciones', ['title' => 'Otra', 'recipients' => [$this->eva->id], 'document' => $this->messageDocument()])
            ->assertCreated();
        $this->assertStringContainsString('/foro/conversaciones/', (string) $answer->json('url'));

        $id = $this->conversation->id()->value;
        $this->postJson("/foro/conversaciones/{$id}/mensajes", ['document' => $this->messageDocument('¿Y tú?')])
            ->assertCreated()
            ->assertJsonPath('entry.html', '<p>¿Y tú?</p>')
            ->assertJsonPath('entry.isMine', true);

        $this->postJson("/foro/conversaciones/{$id}/personas", ['account' => $this->eva->id])->assertCreated();
        $this->postJson("/foro/conversaciones/{$id}/personas", ['account' => $this->eva->id])
            ->assertUnprocessable()->assertJsonPath('reason', 'already_in');

        $this->postJson("/foro/conversaciones/{$id}/salir")->assertOk()->assertJsonPath('url', route('forum.conversations.index'));
        $this->get("/foro/conversaciones/{$id}")->assertNotFound();
    }

    public function test_refusals_name_no_block(): void
    {
        app(BlockService::class)->block($this->ana, $this->luis->id);
        $id = $this->conversation->id()->value;

        $this->actingAs($this->luis)->postJson("/foro/conversaciones/{$id}/mensajes", ['document' => $this->messageDocument()])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'cannot_write')
            ->assertJsonPath('message', 'No puedes escribir en esta conversación.');
        $this->actingAs($this->luis)->postJson('/foro/conversaciones', ['title' => 'Hola', 'recipients' => [$this->ana->id], 'document' => $this->messageDocument()])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'unavailable_people');
        $this->actingAs($this->luis)->get("/foro/conversaciones/{$id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('write.refusal', 'No puedes escribir en esta conversación.')
                ->where('addUrl', null));
        $this->actingAs($this->luis)->postJson("/foro/conversaciones/{$id}/personas", ['account' => $this->eva->id])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'cannot_write');
    }

    public function test_the_people_search_leaves_out_oneself_whoever_blocked_one_and_whoever_may_not_take_part(): void
    {
        $suspended = $this->anAccount();
        $suspended->update(['name' => 'Eva Suspendida', 'display_name' => null]);
        app(SuspensionService::class)->suspend(UserModel::factory()->admin()->create(['name' => 'Moderación Central', 'display_name' => null]), $suspended->id, 'Spam', null);
        app(BlockService::class)->block($this->eva, $this->luis->id);
        $anonymized = $this->anAccount();
        $anonymized->update(['name' => 'Eva Borrada', 'display_name' => null]);
        app(UserServiceInterface::class)->anonymize($anonymized->id);

        $this->actingAs($this->luis)->getJson('/foro/conversaciones/personas?q=e')->assertOk()->assertJsonPath('people', []);
        $this->actingAs($this->luis)->getJson('/foro/conversaciones/personas?q=ev')->assertOk()->assertJsonPath('people', []);
        $this->actingAs($this->ana)->getJson('/foro/conversaciones/personas?q=ev')->assertOk()
            ->assertJsonPath('people', [['id' => strtolower($this->eva->id), 'name' => 'Eva Ruiz']]);
        // Those in the conversation already are not offered to add
        $this->actingAs($this->ana)->getJson('/foro/conversaciones/personas?q=lu&conversacion='.$this->conversation->id()->value)
            ->assertOk()->assertJsonPath('people', []);
        // Never oneself, nor an anonymized account
        $this->actingAs($this->ana)->getJson('/foro/conversaciones/personas?q=ana pérez')->assertOk()->assertJsonPath('people', []);
        $this->actingAs($this->ana)->getJson('/foro/conversaciones/personas?q=anón')->assertOk()->assertJsonPath('people', []);
        $found = $this->actingAs($this->ana)->getJson('/foro/conversaciones/personas?q=ruiz')->json('people');
        $this->assertNotContains('email', array_keys($found[0] ?? []));
    }

    public function test_the_people_search_has_a_limit_per_person_and_minute(): void
    {
        $this->actingAs($this->ana);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/foro/conversaciones/personas?q=ev')->assertOk();
        }

        $this->getJson('/foro/conversaciones/personas?q=ev')->assertTooManyRequests();
        // Someone else still searches
        $this->actingAs($this->luis)->getJson('/foro/conversaciones/personas?q=an')->assertOk();
    }

    public function test_blocks_are_listed_and_undone_from_the_profile(): void
    {
        $this->actingAs($this->ana)->postJson('/foro/bloqueos', ['account' => $this->luis->id])->assertCreated();
        $this->actingAs($this->ana)->postJson('/foro/bloqueos', ['account' => $this->ana->id])->assertUnprocessable();

        $this->actingAs($this->ana)->getJson('/foro/bloqueos')->assertOk()
            ->assertJsonPath('blocks.0.name', 'Luis Gómez')
            ->assertJsonPath('blocks.0.unblockUrl', route('forum.blocks.destroy', ['account' => strtolower($this->luis->id)]));
        // Luis never learns it
        $this->actingAs($this->luis)->getJson('/foro/bloqueos')->assertOk()->assertJsonPath('blocks', []);

        $this->actingAs($this->ana)->deleteJson('/foro/bloqueos/'.$this->luis->id)->assertNoContent();
        $this->actingAs($this->ana)->getJson('/foro/bloqueos')->assertJsonPath('blocks', []);
    }

    public function test_reports_reach_the_forum_moderation_page_as_their_copy_only(): void
    {
        $message = app(ConversationRepositoryInterface::class)->entries($this->conversation->id(), 1, 1)[0];
        $this->actingAs($this->luis)->postJson("/foro/conversaciones/mensajes/{$message->id()->value}/denuncias", ['reason' => 'offensive', 'note' => 'Molesta'])
            ->assertCreated();
        $this->actingAs($this->ana)->postJson("/foro/conversaciones/mensajes/{$message->id()->value}/denuncias", ['reason' => 'spam'])
            ->assertUnprocessable()->assertJsonPath('reason', 'not_allowed');

        $moderator = $this->anAccount(self::MODERATE);
        $this->actingAs($moderator)->get('/foro/moderacion')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Forum/Moderation', false)
                ->has('privateReports', 1)
                ->where('privateReports.0.authorName', 'Ana Pérez')
                ->where('privateReports.0.note', 'Molesta')
                ->where('privateReports.0.context.0.text', 'Hola, ¿quedamos el viernes?'));

        $report = ConversationReportModel::query()->sole();
        $this->actingAs($this->luis)->postJson("/foro/conversaciones/denuncias/{$report->id}/resolver", ['resolution' => 'hidden'])->assertForbidden();
        $this->actingAs($moderator)->postJson("/foro/conversaciones/denuncias/{$report->id}/resolver", ['resolution' => 'hidden', 'reason' => 'Ofensivo'])
            ->assertOk()->assertJsonPath('settled', 1);

        $this->actingAs($this->ana)->get('/foro/conversaciones/'.$this->conversation->id()->value)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entries.0.state', 'hidden')
                ->where('entries.0.html', null)
                ->where('entries.0.hiddenReason', 'Ofensivo'));
        $this->actingAs($this->luis)->get('/foro/conversaciones/'.$this->conversation->id()->value)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('entries.0.hiddenReason', null));
    }

    public function test_a_category_moderator_sees_no_private_reports(): void
    {
        $message = app(ConversationRepositoryInterface::class)->entries($this->conversation->id(), 1, 1)[0];
        app(ConversationReportService::class)->report($this->luis, $message->id(), ReportReason::Spam, null);

        $this->actingAs($this->ana)->get('/foro/moderacion')->assertNotFound();
    }

    public function test_the_forum_index_and_the_pulse_tell_of_unread_conversations(): void
    {
        $this->actingAs($this->luis)->get('/foro')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('conversations.url', route('forum.conversations.index'))
                ->where('conversations.unread', 1));
        $this->actingAs($this->luis)->getJson('/foro/novedades')->assertOk()->assertJsonPath('unreadConversations', 1);
        $this->actingAs($this->ana)->getJson('/foro/novedades')->assertOk()->assertJsonPath('unreadConversations', 0);

        $this->app['auth']->forgetGuards();
        $this->get('/foro')->assertInertia(fn (AssertableInertia $page) => $page->where('conversations', null));
    }

    public function test_the_new_conversation_page_takes_someone_chosen_unless_they_blocked_the_person(): void
    {
        $this->actingAs($this->ana)->get('/foro/conversaciones/nueva?para='.$this->eva->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Forum/NewConversation', false)
                ->where('preselected', ['id' => strtolower($this->eva->id), 'name' => 'Eva Ruiz']));

        app(BlockService::class)->block($this->eva, $this->ana->id);
        $this->actingAs($this->ana)->get('/foro/conversaciones/nueva?para='.$this->eva->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('preselected', null));
    }
}
