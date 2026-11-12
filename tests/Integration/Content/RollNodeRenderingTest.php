<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Content;

use Modules\Forum\Application\Content\DocumentSchema;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Application\Content\Rendering\RollView;
use Modules\Forum\Domain\Dice\Parser;
use Modules\Forum\Domain\Dice\Roller;
use Tests\Support\Modules\ModuleTestCase;
use Modules\Forum\Tests\Unit\Domain\Dice\FixedNumbers;

/**
 * A roll shows its expression, every die (dropped ones struck through, exploded ones marked,
 * both also said in words), the total and the reason; as plain text it reads «2d6+3 Ataque = 11».
 */
final class RollNodeRenderingTest extends ModuleTestCase
{
    private const string ID = '5f6a7b8c-9d0e-4f1a-8b2c-3d4e5f6a7b8c';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_every_die_is_shown_with_what_happened_to_it(): void
    {
        // Four dice keep the three highest; the 6 explodes, and so does the 6 it brings
        $view = $this->thrown('4d6!kh3+2', [2, 6, 3, 5, 6, 1]);

        $html = $this->html('4d6!kh3+2', 'Ataque', $view);

        $this->assertStringContainsString('<div class="forum-roll" data-roll-id="'.self::ID.'">', $html);
        $this->assertStringContainsString('<code class="forum-roll__expression">4d6!kh3+2</code>', $html);
        $this->assertStringContainsString('<span class="forum-roll__reason">Ataque</span>', $html);
        $this->assertStringContainsString('<s class="forum-roll__die forum-roll__die--dropped">2<span class="forum-roll__note"> (descartado)</span></s>', $html);
        $this->assertStringContainsString('<span class="forum-roll__die forum-roll__die--exploded">6<span class="forum-roll__note"> (explosión)</span></span>', $html);
        $this->assertStringContainsString('<span class="forum-roll__const">2</span>', $html);
        $this->assertStringContainsString('= <strong>'.$view->result?->total.'</strong>', $html);
    }

    public function test_a_pool_shows_its_successes_and_a_subtraction_its_sign(): void
    {
        $html = $this->html('5d10>=7-1', null, $this->thrown('5d10>=7-1', [10, 7, 6, 1, 9]));

        $this->assertStringContainsString('(3 éxitos)', $html);
        $this->assertStringContainsString('<span class="forum-roll__op">−</span>', $html);
        $this->assertStringNotContainsString('forum-roll__reason', $html);
    }

    public function test_the_text_of_the_user_is_escaped(): void
    {
        $html = $this->html('<b>1d6</b>', '<script>alert(1)</script>', $this->thrown('1d6', [3]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_roll_that_was_not_thrown_shows_why(): void
    {
        $view = new RollView(self::ID, '2d6+', null, 'syntax', false);

        $html = $this->html('2d6+', 'Rota', $view);

        $this->assertStringContainsString('forum-roll--error', $html);
        $this->assertStringContainsString('No se entiende la expresión.', $html);
        $this->assertSame('2d6+ Rota (No se entiende la expresión.)', $this->text('2d6+', 'Rota', $view));
    }

    public function test_a_roll_without_a_throw_shows_no_result(): void
    {
        $html = (new DocumentRenderer(DocumentSchema::core()))->toHtml($this->document('2d6+3', 'Ataque', null), new RenderContext);

        $this->assertStringContainsString('Sin resultado', $html);
        $this->assertStringNotContainsString('<strong>', $html);
    }

    public function test_the_plain_text_reads_expression_reason_and_total(): void
    {
        $this->assertSame('2d6+3 Ataque = 11', $this->text('2d6+3', 'Ataque', $this->thrown('2d6+3', [4, 4])));
        $this->assertSame('1d20 = 14', $this->text('1d20', null, $this->thrown('1d20', [14])));
    }

    /**
     * @param  list<int>  $faces
     */
    private function thrown(string $expression, array $faces): RollView
    {
        $parsed = (new Parser)->parse($expression);

        return new RollView(self::ID, $parsed->canonical(), (new Roller(new FixedNumbers($faces)))->roll($parsed), null, false);
    }

    private function html(string $expression, ?string $reason, RollView $view): string
    {
        return (new DocumentRenderer(DocumentSchema::core()))->toHtml(
            $this->document($expression, $reason, self::ID),
            new RenderContext(rolls: [self::ID => $view]),
        );
    }

    private function text(string $expression, ?string $reason, RollView $view): string
    {
        return (new DocumentRenderer(DocumentSchema::core()))->toText(
            $this->document($expression, $reason, self::ID),
            new RenderContext(rolls: [self::ID => $view]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $expression, ?string $reason, ?string $rollId): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'roll', 'attrs' => ['expression' => $expression, 'reason' => $reason, 'rollId' => $rollId]]]];
    }
}
