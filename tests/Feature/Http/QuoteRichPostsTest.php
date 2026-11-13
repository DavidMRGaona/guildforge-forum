<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Dice\NumberGenerator;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Unit\Domain\Dice\FixedNumbers;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Quoting a whole post: the page gives each visible post the plain text a quotation of it is
 * checked against, so a post with a roll, an aside or an embed can be quoted. Its rendered text
 * holds words the stored one does not («Tirada», each die, the embed's buttons).
 */
final class QuoteRichPostsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $author;

    private UserModel $reader;

    private Discussion $discussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->app->instance(NumberGenerator::class, new FixedNumbers([4, 3]));
        $this->author = UserModel::factory()->create();
        $this->reader = UserModel::factory()->create();
        [$this->discussion] = $this->aDiscussion($this->aCategory(), authorId: $this->author->id);
    }

    public function test_a_post_with_a_roll_and_an_aside_is_quoted_whole_with_its_quote_text(): void
    {
        $this->actingAs($this->author)->postJson($this->replyUrl(), ['document' => $this->doc([
            $this->paragraph('Al abordaje'),
            ['type' => 'roll', 'attrs' => ['expression' => '2d6+3', 'reason' => 'Ataque', 'rollId' => null]],
            ['type' => 'ooc', 'content' => [$this->paragraph('Vuelvo el lunes')]],
        ])])->assertCreated();

        $quoteText = null;
        $this->actingAs($this->reader)->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$quoteText): AssertableInertia {
                $quoteText = $page->toArray()['props']['posts'][1]['quoteText'] ?? null;

                return $page->etc();
            });

        $this->assertIsString($quoteText);
        $this->assertStringContainsString('Al abordaje', $quoteText);
        $this->assertStringContainsString('2d6+3 Ataque = 10', $quoteText);

        $postId = (string) PostModel::query()->where('discussion_id', $this->discussion->id()->value)->where('number', 2)->value('id');
        $this->quote($postId, $quoteText)->assertCreated();
    }

    public function test_a_hidden_post_gives_no_quote_text(): void
    {
        $reply = $this->aReply($this->discussion, '2026-11-10 18:30:00');
        $reply->hide();
        app(ForumWriter::class)->savePost($reply);

        $this->actingAs($this->reader)->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->whereNot('posts.0.quoteText', null)
                ->where('posts.1.quoteText', null)
                ->etc());
    }

    /**
     * @return TestResponse<Response>
     */
    private function quote(string $postId, string $text): TestResponse
    {
        $paragraphs = array_map($this->paragraph(...), array_values(array_filter(explode("\n", $text), static fn (string $line): bool => trim($line) !== '')));

        return $this->actingAs($this->reader)->postJson($this->replyUrl(), ['document' => $this->doc([
            ['type' => 'postQuote', 'attrs' => ['postId' => $postId], 'content' => $paragraphs],
            $this->paragraph('Eso'),
        ])]);
    }

    private function replyUrl(): string
    {
        return "/foro/temas/{$this->discussion->id()->value}/mensajes";
    }

    private function discussionUrl(): string
    {
        $number = (int) $this->discussion->number();

        return "/foro/t/{$number}-".str($this->discussion->title()->value)->slug();
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
    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }
}
