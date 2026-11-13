<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Modules\Forum\Domain\Entities\Conversation;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Tests\Support\BuildsConversations;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What an open conversation asks for while it is open (forum#89): the entries after the last
 * one it has and those it was told changed, drawn for whoever asks, and only for the people in it.
 */
final class ConversationUpdatesEndpointTest extends ModuleTestCase
{
    use BuildsConversations;
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $luis;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['scout.driver' => 'null']);
        CarbonImmutable::setTestNow('2026-11-20 10:00:00');
        $this->ana = $this->anAccount();
        $this->luis = $this->anAccount();
        $this->conversation = $this->aConversation($this->ana, $this->luis);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_entries_after_the_given_number_come_as_the_page_shows_them(): void
    {
        $this->conversations()->reply($this->luis, $this->conversation->id(), $this->messageDocument('¿A qué hora?'));

        $this->actingAs($this->ana)->getJson($this->url(1))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('highestNumber', 2)
            ->assertJsonPath('more', false)
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.number', 2)
            ->assertJsonPath('entries.0.html', '<p>¿A qué hora?</p>')
            ->assertJsonPath('entries.0.isMine', false);
        $this->actingAs($this->ana)->getJson($this->url(2))->assertNoContent();
    }

    public function test_a_changed_entry_comes_again(): void
    {
        $first = app(ConversationRepositoryInterface::class)->entries($this->conversation->id(), 1, 1)[0];
        $this->conversations()->deleteOwn($this->ana, $first->id());

        $this->actingAs($this->luis)->getJson($this->url(1).'&cambiados=1')
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.number', 1)
            ->assertJsonPath('entries.0.state', 'deleted')
            ->assertJsonPath('entries.0.html', null);
    }

    public function test_nobody_outside_the_conversation_gets_anything(): void
    {
        $this->actingAs($this->anAccount('forum:forum.moderate'))->getJson($this->url(0))->assertNotFound();
        $this->actingAs($this->anAccount())->getJson($this->url(0))->assertNotFound();

        $this->conversations()->leave($this->luis, $this->conversation->id());
        $this->actingAs($this->luis)->getJson($this->url(0))->assertNotFound();
    }

    public function test_guests_and_malformed_numbers_are_refused(): void
    {
        $this->getJson($this->url(0))->assertUnauthorized();
        $this->actingAs($this->ana)->getJson($this->url(0).'&cambiados=x')->assertUnprocessable();
        $this->actingAs($this->ana)->getJson(route('forum.conversations.updates', ['conversation' => $this->conversation->id()->value]))->assertUnprocessable();
    }

    private function url(int $after): string
    {
        return route('forum.conversations.updates', ['conversation' => $this->conversation->id()->value, 'despues' => $after]);
    }
}
