<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Impersonation\Services\ImpersonationServiceInterface;
use App\Domain\Impersonation\Enums\ImpersonationEndReason;
use App\Domain\Impersonation\Enums\ImpersonationMode;
use App\Http\Middleware\EnsurePublicNameIsChosen;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Closure;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Tests\Support\BuildsConversations;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeForumAccess;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Whatever shows a person's name to others in the forum needs a public name (core display_name),
 * decided on the server; what is already published keeps showing as it did.
 *
 * The core asks for it first (core#855): an account without one is answered its 403 on any route
 * with `auth`, so the forum's own check (RequirePublicName, a 422 the editor shows) is a safety net.
 * It is tested where the core steps aside: an impersonation that simulates, in which whoever looks
 * must not choose a name for the account and the forum must refuse just as it would the person.
 */
final class PublicNameRequiredTest extends ModuleTestCase
{
    use BuildsConversations;
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $nameless;

    private UserModel $named;

    private Discussion $discussion;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['scout.driver' => 'null']);
        $access = new FakeForumAccess;
        $this->app->instance(ForumAccessInterface::class, $access);

        $category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->nameless = UserModel::factory()->create(['name' => 'Ana Pérez', 'display_name' => null]);
        $this->named = UserModel::factory()->create(['name' => 'Luis Gómez', 'display_name' => 'Luis']);
        $access->letWrite($this->nameless);
        $access->letWrite($this->named);
        [$this->discussion, $this->post] = $this->aDiscussion($category, authorId: $this->nameless->id);
    }

    public function test_the_core_asks_for_a_public_name_before_the_forum_does(): void
    {
        $id = $this->conversationWithNameless();
        $other = UserModel::factory()->create(['name' => 'Eva', 'display_name' => 'Eva']);
        [, $theirs] = $this->aDiscussion($this->aCategory('otra'), authorId: $this->named->id);
        $category = $this->discussion->categoryId()->value;
        $writes = [
            ['POST', $this->replyUrl(), ['document' => $this->messageDocument()]],
            ['PATCH', "/foro/mensajes/{$this->post->id()->value}", ['document' => $this->messageDocument('Cambiado')]],
            ['POST', '/foro/temas', ['title' => 'Buscamos jugadores', 'category' => $category, 'document' => $this->messageDocument()]],
            ['POST', "/foro/conversaciones/{$id}/mensajes", ['document' => $this->messageDocument()]],
            ['POST', '/foro/conversaciones', ['title' => 'Hola', 'recipients' => [$this->named->id], 'document' => $this->messageDocument()]],
            ['POST', "/foro/conversaciones/{$id}/personas", ['account' => $other->id]],
            ['POST', route('forum.posts.like', ['post' => $theirs->id()->value]), []],
            ['POST', '/foro/tiradas/00000000-0000-4000-8000-000000000000/revelar', []],
        ];

        foreach ($writes as [$method, $url, $body]) {
            $this->actingAs($this->nameless)->json($method, $url, $body)
                ->assertForbidden()
                ->assertHeader(EnsurePublicNameIsChosen::JSON_ERROR_HEADER, '1')
                ->assertJsonPath('error', EnsurePublicNameIsChosen::JSON_ERROR_CODE)
                ->assertJsonMissingPath('reason');
        }

        $stored = app(PostRepositoryInterface::class)->find($this->post->id());
        $this->assertSame($this->post->body()->plainText, $stored?->body()->plainText);
    }

    public function test_replying_needs_a_public_name(): void
    {
        $this->simulatingAs($this->nameless, function (): void {
            $this->postJson($this->replyUrl(), ['document' => $this->messageDocument()])
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'public_name_required')
                ->assertJsonPath('errors.display_name.0', 'Para hacer esto en el foro necesitas un nombre público. Elígelo en Ajustes de la cuenta y vuelve a intentarlo.');
        });

        $this->actingAs($this->named)->postJson($this->replyUrl(), ['document' => $this->messageDocument()])->assertCreated();
    }

    public function test_a_blank_public_name_counts_as_none(): void
    {
        $this->nameless->forceFill(['display_name' => '   '])->save();

        $this->simulatingAs($this->nameless, function (): void {
            $this->postJson($this->replyUrl(), ['document' => $this->messageDocument()])
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'public_name_required');
        });
    }

    public function test_editing_needs_a_public_name_but_the_post_stays_as_it_was(): void
    {
        $this->simulatingAs($this->nameless, function (): void {
            $this->patchJson("/foro/mensajes/{$this->post->id()->value}", ['document' => $this->messageDocument('Cambiado')])
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'public_name_required');
        });

        $stored = app(PostRepositoryInterface::class)->find($this->post->id());
        $this->assertSame($this->post->body()->plainText, $stored?->body()->plainText);
    }

    public function test_starting_a_discussion_needs_a_public_name(): void
    {
        $category = $this->discussion->categoryId()->value;
        $body = ['title' => 'Buscamos jugadores', 'category' => $category, 'document' => $this->messageDocument()];

        $this->simulatingAs($this->nameless, function () use ($body): void {
            $this->postJson('/foro/temas', $body)
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'public_name_required');
        });
        $this->actingAs($this->named)->postJson('/foro/temas', $body)->assertCreated();
    }

    public function test_private_messages_need_a_public_name(): void
    {
        $id = $this->conversationWithNameless();

        $this->simulatingAs($this->nameless, function () use ($id): void {
            $this->postJson("/foro/conversaciones/{$id}/mensajes", ['document' => $this->messageDocument()])
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'public_name_required');
            $this->postJson('/foro/conversaciones', ['title' => 'Hola', 'recipients' => [$this->named->id], 'document' => $this->messageDocument()])
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'public_name_required');
        });
    }

    public function test_liking_needs_a_public_name_but_taking_a_like_back_does_not(): void
    {
        [, $theirs] = $this->aDiscussion($this->aCategory('otra'), authorId: $this->named->id);
        $url = route('forum.posts.like', ['post' => $theirs->id()->value]);

        $this->simulatingAs($this->nameless, function () use ($url): void {
            $this->postJson($url)
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'public_name_required');
            $this->deleteJson($url)->assertOk();
        });
    }

    public function test_only_people_with_a_public_name_are_offered_in_mentions_and_private_messages(): void
    {
        $this->actingAs($this->named)->getJson("/foro/temas/{$this->discussion->id()->value}/menciones?q=a")
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($this->named)->getJson('/foro/conversaciones/personas?q=an')
            ->assertOk()
            ->assertJsonPath('people', []);

        $this->nameless->forceFill(['display_name' => 'Ana'])->save();
        $this->actingAs($this->named)->getJson('/foro/conversaciones/personas?q=an')
            ->assertOk()
            ->assertJsonPath('people.0.name', 'Ana');
    }

    public function test_a_forged_mention_of_an_account_without_a_public_name_is_refused(): void
    {
        $mention = fn (UserModel $who): array => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => ['id' => strtolower($who->id)]]]]]];

        $this->actingAs($this->named)->postJson($this->replyUrl(), ['document' => $mention($this->nameless)])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'unknown_account');

        $this->nameless->forceFill(['display_name' => 'Ana'])->save();
        $this->actingAs($this->named)->postJson($this->replyUrl(), ['document' => $mention($this->nameless)])->assertCreated();
    }

    public function test_an_edit_keeps_a_mention_the_post_already_had(): void
    {
        $this->nameless->forceFill(['display_name' => 'Ana'])->save();
        $mention = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => ['id' => strtolower($this->nameless->id)]]]]]];
        $postId = $this->actingAs($this->named)->postJson($this->replyUrl(), ['document' => $mention])->assertCreated()->json('post.id');

        // The mentioned person clears their public name afterwards: the published mention stays
        $this->nameless->forceFill(['display_name' => null])->save();
        $this->actingAs($this->named)->patchJson("/foro/mensajes/{$postId}", ['document' => $mention])->assertOk();
    }

    public function test_conversations_refuse_people_without_a_public_name(): void
    {
        $this->actingAs($this->named)->postJson('/foro/conversaciones', ['title' => 'Hola', 'recipients' => [$this->nameless->id], 'document' => $this->messageDocument()])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'people_without_public_name')
            ->assertJsonPath('message', 'Alguna de esas personas aún no tiene nombre público.');

        $third = UserModel::factory()->create(['name' => 'Eva', 'display_name' => 'Eva']);
        $id = $this->aConversation($this->named, $third)->id()->value;
        $this->actingAs($this->named)->postJson("/foro/conversaciones/{$id}/personas", ['account' => $this->nameless->id])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'person_without_public_name')
            ->assertJsonPath('message', 'Esa persona aún no tiene nombre público.');
    }

    public function test_adding_someone_already_in_keeps_its_reason_even_without_a_public_name(): void
    {
        $id = $this->conversationWithNameless();

        $this->actingAs($this->named)->postJson("/foro/conversaciones/{$id}/personas", ['account' => $this->nameless->id])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'already_in');
    }

    public function test_adding_people_needs_a_public_name_of_the_one_who_adds(): void
    {
        $other = UserModel::factory()->create(['name' => 'Eva', 'display_name' => 'Eva']);
        $id = $this->conversationWithNameless();

        $this->simulatingAs($this->nameless, function () use ($id, $other): void {
            $this->postJson("/foro/conversaciones/{$id}/personas", ['account' => $other->id])
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'public_name_required');
        });
    }

    public function test_writing_to_someone_without_a_public_name_does_not_preselect_them(): void
    {
        $this->actingAs($this->named)->get('/foro/conversaciones/nueva?para='.$this->nameless->id)
            ->assertInertia(fn ($page) => $page->where('preselected', null));

        $this->nameless->forceFill(['display_name' => 'Ana'])->save();
        $this->actingAs($this->named)->get('/foro/conversaciones/nueva?para='.$this->nameless->id)
            ->assertInertia(fn ($page) => $page->where('preselected.name', 'Ana'));
    }

    public function test_revealing_a_roll_needs_a_public_name(): void
    {
        $this->simulatingAs($this->nameless, function (): void {
            $this->postJson('/foro/tiradas/00000000-0000-4000-8000-000000000000/revelar')
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'public_name_required');
        });
    }

    /**
     * Requests made while an administrator impersonates the account simulating: the core never
     * asks for a public name then, so the forum's own check is what refuses. Plain requests
     * inside, with no actingAs(): the administrator stays the session's account.
     *
     * @param  Closure(): void  $requests
     */
    private function simulatingAs(UserModel $account, Closure $requests): void
    {
        $admin = UserModel::factory()->admin()->create();
        $impersonation = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin);
        $impersonation->start($admin, $account, ImpersonationMode::Simulate);

        try {
            $requests();
        } finally {
            $impersonation->stop(ImpersonationEndReason::Stopped);
        }
    }

    /**
     * A conversation the nameless account is in, started while it still had a name (as older ones are).
     */
    private function conversationWithNameless(): string
    {
        $this->nameless->forceFill(['display_name' => 'Ana'])->save();
        $id = $this->aConversation($this->named, $this->nameless)->id()->value;
        $this->nameless->forceFill(['display_name' => null])->save();

        return $id;
    }

    private function replyUrl(): string
    {
        return "/foro/temas/{$this->discussion->id()->value}/mensajes";
    }
}
