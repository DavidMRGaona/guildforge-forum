<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Content;

use Modules\Forum\Application\Content\DocumentSchema;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Application\Content\Rendering\RollView;
use Modules\Forum\Domain\Dice\RollResult;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Asides out of character look apart from the story and say so in words, and the rolls of a
 * post written in character say who throws them, except inside an aside.
 */
final class OocRenderingTest extends ModuleTestCase
{
    private const string ROLL = '5b0f9a2c-8d3e-4f1a-9b6c-7d8e9f0a1b2c';

    private const string OTHER_ROLL = '6c1a0b3d-9e4f-4a2b-8c7d-8e9f0a1b2c3d';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_an_aside_holds_blocks_and_is_never_empty(): void
    {
        $raw = $this->doc([['type' => 'ooc', 'content' => [$this->paragraph('Vuelvo en cinco minutos')]]]);
        $this->assertSame($raw, DocumentSchema::core()->validate($raw)->toArray());

        try {
            DocumentSchema::core()->validate($this->doc([['type' => 'ooc', 'content' => []]]));
            $this->fail('An empty aside was taken.');
        } catch (InvalidDocumentException $exception) {
            $this->assertSame(InvalidDocumentException::MISSING_CONTENT, $exception->reason);
        }

        try {
            DocumentSchema::core()->validate($this->doc([['type' => 'ooc', 'attrs' => ['by' => 'x'], 'content' => [$this->paragraph('a')]]]));
            $this->fail('An aside with attributes was taken.');
        } catch (InvalidDocumentException $exception) {
            $this->assertSame(InvalidDocumentException::INVALID_ATTRIBUTE, $exception->reason);
        }
    }

    public function test_an_aside_is_labelled_in_words_and_in_the_plain_text(): void
    {
        $document = $this->doc([['type' => 'ooc', 'content' => [$this->paragraph('<b>Vuelvo</b> luego')]]]);

        $this->assertSame(
            '<aside class="forum-ooc"><p class="forum-ooc__label">Fuera de personaje</p><p>&lt;b&gt;Vuelvo&lt;/b&gt; luego</p></aside>',
            $this->renderer()->toHtml($document, new RenderContext),
        );
        $this->assertSame('(Fuera de personaje) <b>Vuelvo</b> luego', $this->renderer()->toText($document, new RenderContext));
    }

    public function test_a_roll_in_character_names_who_throws_it_but_not_inside_an_aside(): void
    {
        $document = $this->doc([
            ['type' => 'roll', 'attrs' => ['expression' => '1d20+5', 'reason' => 'Ataque', 'rollId' => self::ROLL]],
            ['type' => 'ooc', 'content' => [
                ['type' => 'roll', 'attrs' => ['expression' => '1d6', 'reason' => null, 'rollId' => self::OTHER_ROLL]],
            ]],
        ]);
        $context = new RenderContext(rolls: [
            self::ROLL => $this->rollView(self::ROLL, '1d20+5', 17),
            self::OTHER_ROLL => $this->rollView(self::OTHER_ROLL, '1d6', 4),
        ]);

        $inCharacter = $this->renderer()->toHtml($document, $context->withSpeaker('Arwen <Undómiel>'));
        $this->assertSame(1, substr_count($inCharacter, '<span class="forum-roll__label">Arwen &lt;Undómiel&gt; tira</span>'));
        $this->assertSame(1, substr_count($inCharacter, '<span class="forum-roll__label">Tirada</span>'));
        $this->assertLessThan(strpos($inCharacter, 'forum-ooc'), strpos($inCharacter, 'Arwen'));

        $outOfCharacter = $this->renderer()->toHtml($document, $context);
        $this->assertStringNotContainsString(' tira</span>', $outOfCharacter);
        $this->assertSame(2, substr_count($outOfCharacter, '<span class="forum-roll__label">Tirada</span>'));
    }

    private function rollView(string $id, string $expression, int $total): RollView
    {
        $result = new RollResult([['kind' => 'dice', 'sign' => 1, 'spec' => $expression, 'dice' => [['v' => $total, 'k' => true]], 'subtotal' => $total]], $total);

        return new RollView($id, $expression, $result, null, false);
    }

    private function renderer(): DocumentRenderer
    {
        return new DocumentRenderer(DocumentSchema::core());
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
