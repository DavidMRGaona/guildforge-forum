<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Testing\TestResponse;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Domain\Dice\NumberGenerator;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiceRollModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeForumAccess;
use Modules\Forum\Tests\Unit\Domain\Dice\FixedNumbers;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What the editor can and cannot do with dice: it sends the expression and the reason, the
 * server throws, and a document that carries a result or someone else's roll is refused.
 */
final class PostRollEndpointsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $author;

    private Discussion $discussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $access = new FakeForumAccess;
        $this->app->instance(ForumAccessInterface::class, $access);
        $this->app->instance(NumberGenerator::class, new FixedNumbers([4, 2, 6, 1]));

        $this->author = UserModel::factory()->create();
        $access->letWrite($this->author);
        [$this->discussion] = $this->aDiscussion($this->aCategory(), authorId: $this->author->id);
    }

    public function test_a_reply_with_a_roll_is_thrown_by_the_server_and_answers_with_it(): void
    {
        $response = $this->reply([$this->roll('2d6+3', 'Ataque')])->assertCreated();

        $html = $response->json('post.html');
        $this->assertStringContainsString('2d6+3', $html);
        $this->assertStringContainsString('= <strong>9</strong>', $html);

        $stored = app(PostRepositoryInterface::class)->inDiscussion($this->discussion->id())[1];
        $this->assertSame('2d6+3 Ataque = 9', $stored->body()->plainText);
        $this->assertSame(1, DiceRollModel::query()->count());
        $this->assertSame(DiceRollModel::query()->value('id'), $stored->body()->document['content'][0]['attrs']['rollId']);
    }

    public function test_a_reply_cannot_choose_the_result(): void
    {
        $this->reply([['type' => 'roll', 'attrs' => ['expression' => '1d20', 'reason' => null, 'rollId' => null, 'total' => 20]]])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'invalid_attribute');

        $this->reply([$this->roll('1d20', null, '9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6')])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'unknown_roll')
            ->assertJsonPath('message', 'El mensaje lleva una tirada que no es suya o que ya se quitó.');

        $this->assertSame(0, DiceRollModel::query()->count());
    }

    public function test_an_edit_cannot_take_a_roll_from_another_post(): void
    {
        $this->reply([$this->roll('2d6+3')])->assertCreated();
        $foreign = DiceRollModel::query()->firstOrFail()->id;
        $mine = $this->reply([$this->roll('1d6')])->assertCreated()->json('post.id');

        $this->actingAs($this->author)
            ->patchJson("/foro/mensajes/{$mine}", ['document' => $this->doc([$this->roll('2d6+3', null, $foreign)])])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'unknown_roll');
    }

    /**
     * @param  list<array<string, mixed>>  $rolls
     */
    private function reply(array $rolls): TestResponse
    {
        return $this->actingAs($this->author)->postJson("/foro/temas/{$this->discussion->id()->value}/mensajes", ['document' => $this->doc($rolls)]);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @return array<string, mixed>
     */
    private function roll(string $expression, ?string $reason = null, ?string $rollId = null): array
    {
        return ['type' => 'roll', 'attrs' => ['expression' => $expression, 'reason' => $reason, 'rollId' => $rollId]];
    }
}
