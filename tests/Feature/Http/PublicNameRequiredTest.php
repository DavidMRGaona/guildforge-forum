<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
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

    public function test_replying_needs_a_public_name(): void
    {
        $this->actingAs($this->nameless)->postJson($this->replyUrl(), ['document' => $this->messageDocument()])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'public_name_required')
            ->assertJsonPath('errors.display_name.0', 'Para escribir en el foro necesitas un nombre público. Elígelo y vuelve a intentarlo.');

        $this->actingAs($this->named)->postJson($this->replyUrl(), ['document' => $this->messageDocument()])->assertCreated();
    }

    public function test_a_blank_public_name_counts_as_none(): void
    {
        $this->nameless->forceFill(['display_name' => '   '])->save();

        $this->actingAs($this->nameless)->postJson($this->replyUrl(), ['document' => $this->messageDocument()])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'public_name_required');
    }

    public function test_editing_needs_a_public_name_but_the_post_stays_as_it_was(): void
    {
        $this->actingAs($this->nameless)->patchJson("/foro/mensajes/{$this->post->id()->value}", ['document' => $this->messageDocument('Cambiado')])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'public_name_required');

        $stored = app(PostRepositoryInterface::class)->find($this->post->id());
        $this->assertSame($this->post->body()->plainText, $stored?->body()->plainText);
    }

    public function test_starting_a_discussion_needs_a_public_name(): void
    {
        $category = $this->discussion->categoryId()->value;
        $body = ['title' => 'Buscamos jugadores', 'category' => $category, 'document' => $this->messageDocument()];

        $this->actingAs($this->nameless)->postJson('/foro/temas', $body)
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'public_name_required');
        $this->actingAs($this->named)->postJson('/foro/temas', $body)->assertCreated();
    }

    public function test_private_messages_need_a_public_name(): void
    {
        $conversation = $this->aConversation($this->named, $this->nameless);
        $id = $conversation->id()->value;

        $this->actingAs($this->nameless)->postJson("/foro/conversaciones/{$id}/mensajes", ['document' => $this->messageDocument()])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'public_name_required');
        $this->actingAs($this->nameless)->postJson('/foro/conversaciones', ['title' => 'Hola', 'recipients' => [$this->named->id], 'document' => $this->messageDocument()])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'public_name_required');
    }

    public function test_liking_needs_a_public_name_but_taking_a_like_back_does_not(): void
    {
        [, $theirs] = $this->aDiscussion($this->aCategory('otra'), authorId: $this->named->id);
        $url = route('forum.posts.like', ['post' => $theirs->id()->value]);

        $this->actingAs($this->nameless)->postJson($url)
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'public_name_required');
        $this->actingAs($this->nameless)->deleteJson($url)->assertOk();
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

    private function replyUrl(): string
    {
        return "/foro/temas/{$this->discussion->id()->value}/mensajes";
    }
}
