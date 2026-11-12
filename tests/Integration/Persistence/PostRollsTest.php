<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Persistence;

use DateTimeImmutable;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\PostBodyFactory;
use Modules\Forum\Application\Services\PostRenderer;
use Modules\Forum\Domain\Dice\Limits;
use Modules\Forum\Domain\Dice\NumberGenerator;
use Modules\Forum\Domain\Entities\DiceRoll;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Repositories\DiceRollRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRevisionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiceRollModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Unit\Domain\Dice\FixedNumbers;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The server throws a post's dice when it is written and keeps the throw apart from the
 * document; editing never throws a roll again, and nobody can bring a result in.
 */
final class PostRollsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
    }

    public function test_publishing_throws_each_roll_once_and_leaves_only_its_id_in_the_document(): void
    {
        $this->numbers([4, 2]);
        [$discussion] = $this->aDiscussion($this->aCategory());

        $post = $this->reply($discussion, [$this->roll('2D6 + 3', 'Ataque')]);

        $rolls = $this->rollsOf($post);
        $this->assertCount(1, $rolls);
        $this->assertSame('2d6+3', $rolls[0]->expression);
        $this->assertSame(9, $rolls[0]->result?->total);
        $this->assertFalse($rolls[0]->addedOnEdit);
        $this->assertSame(1, $rolls[0]->position);

        $node = $post->body()->document['content'][1];
        $this->assertSame(['expression' => '2d6+3', 'reason' => 'Ataque', 'rollId' => $rolls[0]->id], $node['attrs']);
        $this->assertStringNotContainsString('"total"', json_encode($post->body()->document, JSON_THROW_ON_ERROR));
        $this->assertSame("Tira\n2d6+3 Ataque = 9", $post->body()->plainText);
    }

    public function test_reading_the_post_again_shows_the_same_throw(): void
    {
        $this->numbers([4, 2]);
        [$discussion] = $this->aDiscussion($this->aCategory());
        $post = $this->reply($discussion, [$this->roll('2d6+3', 'Ataque')]);

        $stored = app(PostRepositoryInterface::class)->findOrFail($post->id());
        $html = app(PostRenderer::class)->toHtml([$stored->body()], null)[0];

        $this->assertStringContainsString('<code class="forum-roll__expression">2d6+3</code>', $html);
        $this->assertStringContainsString('<span class="forum-roll__reason">Ataque</span>', $html);
        $this->assertStringContainsString('= <strong>9</strong>', $html);
    }

    public function test_a_roll_that_does_not_read_is_stored_with_its_error_and_the_rest_is_published(): void
    {
        $this->numbers([5]);
        [$discussion] = $this->aDiscussion($this->aCategory());

        $post = $this->reply($discussion, [$this->roll('2d6+', 'Rota'), $this->roll('1d6', 'Buena')]);

        [$broken, $good] = $this->rollsOf($post);
        $this->assertNull($broken->result);
        $this->assertSame('syntax', $broken->error);
        $this->assertSame(5, $good->result?->total);
        $this->assertStringContainsString('2d6+ Rota (', $post->body()->plainText);
        $this->assertStringContainsString('1d6 Buena = 5', $post->body()->plainText);

        $html = app(PostRenderer::class)->toHtml([$post->body()], null)[0];
        $this->assertStringContainsString('forum-roll--error', $html);
    }

    public function test_the_dice_of_a_post_have_a_budget_and_the_roll_that_passes_it_is_not_thrown(): void
    {
        $this->numbers(array_fill(0, 300, 1));
        [$discussion] = $this->aDiscussion($this->aCategory());

        $rolls = [];
        for ($i = 0; $i < 4; $i++) {
            $rolls[] = $this->roll('100d6', "Tanda {$i}");
        }
        $post = $this->reply($discussion, $rolls);

        $stored = $this->rollsOf($post);
        $this->assertSame(300, array_sum(array_map(static fn (DiceRoll $roll): int => $roll->diceCount, $stored)));
        $this->assertSame([null, null, null, 'too_many_dice_in_post'], array_map(static fn (DiceRoll $roll): ?string => $roll->error, $stored));
    }

    public function test_a_document_cannot_carry_a_result_or_more_rolls_than_the_limit(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $author = $this->anAccount();

        $withResult = $this->document([['type' => 'roll', 'attrs' => ['expression' => '1d20', 'reason' => null, 'total' => 20]]]);
        $this->assertRefused(InvalidDocumentException::INVALID_ATTRIBUTE, fn () => app(PostBodyFactory::class)->fromInput($withResult, $author));

        $withDice = $this->document([['type' => 'roll', 'attrs' => ['expression' => '1d20', 'result' => ['total' => 20]]]]);
        $this->assertRefused(InvalidDocumentException::INVALID_ATTRIBUTE, fn () => app(PostBodyFactory::class)->fromInput($withDice, $author));

        $tooMany = $this->document(array_fill(0, Limits::MAX_ROLLS_PER_POST + 1, $this->roll('1d6')));
        $this->assertRefused(InvalidDocumentException::TOO_MANY_ROLLS, fn () => app(PostBodyFactory::class)->fromInput($tooMany, $author));

        $atTheLimit = $this->document(array_fill(0, Limits::MAX_ROLLS_PER_POST, $this->roll('1d6')));
        $this->assertSame(Limits::MAX_ROLLS_PER_POST, count(app(PostBodyFactory::class)->fromInput($atTheLimit, $author)->document['content']) - 1);
        $this->assertSame(0, $this->countRolls($discussion->id()->value));
    }

    public function test_a_new_post_cannot_name_a_roll(): void
    {
        $this->numbers([6]);
        [$discussion, $first] = $this->aDiscussion($this->aCategory());
        $other = $this->reply($discussion, [$this->roll('1d6')]);
        $stolen = $this->rollsOf($other)[0];

        $this->assertRefused(InvalidDocumentException::UNKNOWN_ROLL, fn () => $this->reply($discussion, [$this->roll('1d6', null, $stolen->id)]));
        $this->assertRefused(InvalidDocumentException::UNKNOWN_ROLL, fn () => $this->reply($discussion, [$this->roll('1d6', null, '9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6')]));
        $this->assertCount(1, $this->rollsOf($other));
        $this->assertSame([], app(DiceRollRepositoryInterface::class)->ofPost($first->id()));
    }

    public function test_editing_keeps_the_rolls_it_has_throws_new_ones_once_and_keeps_the_removed_ones(): void
    {
        $this->numbers([4, 2, 6, 3, 1]);
        [$discussion] = $this->aDiscussion($this->aCategory());
        $post = $this->reply($discussion, [$this->roll('2d6+3', 'Ataque'), $this->roll('1d6', 'Daño')]);
        [$attack, $damage] = $this->rollsOf($post);
        $this->assertSame([9, 6], [$attack->result?->total, $damage->result?->total]);

        // Keeps the attack (with another reason), drops the damage and adds a new roll
        $edited = $this->edit($post, [
            $this->roll('2d6+3', 'Ataque con espada', $attack->id),
            $this->roll('1d20', 'Percepción'),
        ]);

        $rolls = $this->allRollsOf($edited->id());
        $this->assertCount(3, $rolls);
        $byId = [];
        foreach ($rolls as $roll) {
            $byId[$roll->id] = $roll;
        }
        $this->assertSame(9, $byId[$attack->id]->result?->total, 'The attack was not thrown again');
        $this->assertFalse($byId[$attack->id]->isRemoved());
        $this->assertTrue($byId[$damage->id]->isRemoved(), 'The removed roll stays, marked');
        $this->assertSame(6, $byId[$damage->id]->result?->total);

        $new = array_values(array_filter($rolls, static fn (DiceRoll $roll): bool => ! in_array($roll->id, [$attack->id, $damage->id], true)))[0];
        $this->assertTrue($new->addedOnEdit);
        $this->assertSame(2, $new->position);
        $this->assertSame(3, $new->result?->total);

        $this->assertSame("Tira\n2d6+3 Ataque con espada = 9\n1d20 Percepción = 3", $edited->body()->plainText);

        // The version it replaced still shows the removed roll as it was thrown
        $revision = app(PostRevisionRepositoryInterface::class)->ofPost($post->id())[0];
        $this->assertSame("Tira\n2d6+3 Ataque = 9\n1d6 Daño = 6", $revision->body->plainText);
        $html = app(PostRenderer::class)->toHtml([$revision->body], null)[0];
        $this->assertStringContainsString('= <strong>6</strong>', $html);
    }

    public function test_the_roll_added_on_editing_is_marked_when_shown(): void
    {
        $this->numbers([3]);
        [$discussion] = $this->aDiscussion($this->aCategory());
        $post = $this->reply($discussion, []);

        $edited = $this->edit($post, [$this->roll('1d6', 'Tarde')]);

        $html = app(PostRenderer::class)->toHtml([$edited->body()], null)[0];
        $this->assertStringContainsString('forum-roll__badge', $html);
        $this->assertStringContainsString('Añadida al editar', $html);
    }

    public function test_an_edit_that_changes_nothing_throws_nothing_and_keeps_no_revision(): void
    {
        $this->numbers([4, 2]);
        [$discussion] = $this->aDiscussion($this->aCategory());
        $post = $this->reply($discussion, [$this->roll('2d6+3')]);
        $roll = $this->rollsOf($post)[0];

        // The editor sends the document back as it got it, with the roll's id
        $same = $this->edit($post, [$this->roll('2d6+3', null, $roll->id)]);

        $this->assertNull($same->editedAt());
        $this->assertSame([], app(PostRevisionRepositoryInterface::class)->ofPost($post->id()));
        $this->assertCount(1, $this->allRollsOf($post->id()));
    }

    public function test_an_edit_cannot_name_a_roll_the_post_does_not_hold(): void
    {
        $this->numbers([4, 2, 6]);
        [$discussion] = $this->aDiscussion($this->aCategory());
        $mine = $this->reply($discussion, [$this->roll('2d6+3')]);
        $theirs = $this->reply($discussion, [$this->roll('1d6')]);
        $own = $this->rollsOf($mine)[0];
        $foreign = $this->rollsOf($theirs)[0];

        // Another post's roll, a made-up id, the same roll twice and an expression that is not the one thrown
        $this->assertRefused(InvalidDocumentException::UNKNOWN_ROLL, fn () => $this->edit($mine, [$this->roll('1d6', null, $foreign->id)]));
        $this->assertRefused(InvalidDocumentException::UNKNOWN_ROLL, fn () => $this->edit($mine, [$this->roll('2d6+3', null, '9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6')]));
        $this->assertRefused(InvalidDocumentException::UNKNOWN_ROLL, fn () => $this->edit($mine, [$this->roll('2d6+3', null, $own->id), $this->roll('2d6+3', null, $own->id)]));
        $this->assertRefused(InvalidDocumentException::UNKNOWN_ROLL, fn () => $this->edit($mine, [$this->roll('1d20+30', null, $own->id)]));

        // Nothing changed
        $this->assertSame([$own->id], array_map(static fn (DiceRoll $roll): string => $roll->id, $this->allRollsOf($mine->id())));
        $this->assertSame([], app(PostRevisionRepositoryInterface::class)->ofPost($mine->id()));
    }

    public function test_a_roll_an_edit_took_out_cannot_be_brought_back_with_its_id(): void
    {
        $this->numbers([4, 2, 5]);
        [$discussion] = $this->aDiscussion($this->aCategory());
        $post = $this->reply($discussion, [$this->roll('2d6+3')]);
        $removed = $this->rollsOf($post)[0];
        $this->edit($post, [$this->roll('1d6')]);

        $this->assertRefused(InvalidDocumentException::UNKNOWN_ROLL, fn () => $this->edit($post, [$this->roll('2d6+3', null, $removed->id)]));
    }

    public function test_a_roll_that_was_refused_stays_refused_when_the_post_is_edited(): void
    {
        $this->numbers([]);
        [$discussion] = $this->aDiscussion($this->aCategory());
        $post = $this->reply($discussion, [$this->roll('nada')]);
        $broken = $this->rollsOf($post)[0];

        $edited = $this->edit($post, [$this->roll('nada', 'Con motivo', $broken->id)]);

        $this->assertSame('syntax', $this->allRollsOf($edited->id())[0]->error);
        $this->assertCount(1, $this->allRollsOf($edited->id()));
    }

    public function test_deleting_the_content_of_a_post_for_good_drops_its_rolls(): void
    {
        $this->numbers([3]);
        [$discussion] = $this->aDiscussion($this->aCategory());
        $post = $this->reply($discussion, [$this->roll('1d6')]);
        $this->writer()->deletePostByAuthor($post->id(), new DateTimeImmutable('2026-11-10 19:30:00'));

        $this->writer()->purgeDeletedPost($post->id(), new DateTimeImmutable('2026-12-30 04:10:00'));

        $this->assertSame([], $this->allRollsOf($post->id()));
    }

    /**
     * @param  list<int>  $values
     */
    private function numbers(array $values): void
    {
        $this->app->instance(NumberGenerator::class, new FixedNumbers($values));
    }

    private function writer(): ForumWriter
    {
        return app(ForumWriter::class);
    }

    /**
     * @param  list<array<string, mixed>>  $rolls
     */
    private function reply(object $discussion, array $rolls): Post
    {
        return $this->writer()->reply($discussion->id(), PostId::generate(), null, $this->body($rolls), new DateTimeImmutable('2026-11-10 19:00:00'), needsApproval: false);
    }

    /**
     * @param  list<array<string, mixed>>  $rolls
     */
    private function edit(Post $post, array $rolls): Post
    {
        return $this->writer()->editPost($post->id(), $this->body($rolls), null, new DateTimeImmutable('2026-11-10 20:00:00'));
    }

    /**
     * @param  list<array<string, mixed>>  $rolls
     */
    private function body(array $rolls): PostBody
    {
        $document = $this->document($rolls);

        return new PostBody($document, 'Tira');
    }

    /**
     * @param  list<array<string, mixed>>  $rolls
     * @return array<string, mixed>
     */
    private function document(array $rolls): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Tira']]],
            ...$rolls,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function roll(string $expression, ?string $reason = null, ?string $rollId = null): array
    {
        return ['type' => 'roll', 'attrs' => ['expression' => $expression, 'reason' => $reason, 'rollId' => $rollId]];
    }

    /**
     * The post's rolls that were not taken out, in document order.
     *
     * @return list<DiceRoll>
     */
    private function rollsOf(Post $post): array
    {
        $rolls = array_values(array_filter($this->allRollsOf($post->id()), static fn (DiceRoll $roll): bool => ! $roll->isRemoved()));
        usort($rolls, static fn (DiceRoll $a, DiceRoll $b): int => $a->position <=> $b->position);

        return $rolls;
    }

    /**
     * @return list<DiceRoll>
     */
    private function allRollsOf(PostId $postId): array
    {
        return app(DiceRollRepositoryInterface::class)->ofPost($postId);
    }

    private function countRolls(string $discussionId): int
    {
        return DiceRollModel::query()->count();
    }

    private function assertRefused(string $reason, callable $write): void
    {
        try {
            $write();
            $this->fail('The document was accepted.');
        } catch (InvalidDocumentException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
