<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Content;

use Modules\Forum\Application\Content\DocumentSchema;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Application\Content\Rendering\QuoteSource;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The renderer only knows the schema's nodes and marks and escapes every piece of text, so
 * what someone wrote can never become markup.
 */
final class DocumentRendererTest extends ModuleTestCase
{
    private const string ACCOUNT = '9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6';

    private const string POST = '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';

    private const string IMAGE = 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_every_core_node_and_mark_is_rendered(): void
    {
        $html = $this->renderer()->toHtml($this->fullDocument(), $this->context());

        $this->assertSame(
            '<h2>Crónica</h2>'
            .'<p><strong>Negrita</strong><em><s> cursiva</s></em><br><code>código</code>'
            .'<a href="https://example.com/a?b=1&amp;c=2" target="_blank" rel="nofollow ugc noopener noreferrer"> enlace</a> '
            .'<span class="forum-mention" data-account-id="'.self::ACCOUNT.'">@Ana Pérez</span></p>'
            .'<blockquote><p>Cita libre</p></blockquote>'
            .'<blockquote class="forum-quote"><p class="forum-quote__source"><a href="https://example.com/foro/m/1">Luis escribió:</a></p><p>Lo que dijo</p></blockquote>'
            .'<ul><li><p>Uno</p><ol start="3"><li><p>Tres</p></li></ol></li></ul>'
            .'<pre><code class="language-php">echo 1;'."\n".'echo 2;</code></pre>'
            .'<hr>'
            .'<figure class="forum-image"><img src="https://cdn.example.com/'.self::IMAGE.'" alt="Mapa" loading="lazy" decoding="async"></figure>'
            .'<p></p>',
            $html,
        );
    }

    public function test_the_plain_text_reads_like_a_mail(): void
    {
        $text = $this->renderer()->toText($this->fullDocument(), $this->context());

        $this->assertSame(
            "Crónica\n"
            ."Negrita cursiva\ncódigo enlace @Ana Pérez\n"
            ."> Cita libre\n"
            ."> Lo que dijo\n"
            ."- Uno\n3. Tres\n"
            ."echo 1;\necho 2;\n"
            ."\n"
            .'Mapa',
            $text,
        );
    }

    public function test_text_that_looks_like_markup_is_escaped(): void
    {
        $html = $this->renderer()->toHtml($this->doc([
            $this->paragraph([$this->text('<script>alert("x")</script> & \'y\'')]),
            ['type' => 'codeBlock', 'content' => [$this->text('<?php echo "<b>"; ?>')]],
        ]), $this->context());

        $this->assertSame(
            '<p>&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &apos;y&apos;</p>'
            .'<pre><code>&lt;?php echo &quot;&lt;b&gt;&quot;; ?&gt;</code></pre>',
            $html,
        );
    }

    public function test_names_and_quote_authors_are_escaped(): void
    {
        $context = new RenderContext(
            accountNames: [self::ACCOUNT => '<img src=x onerror=alert(1)>'],
            quotes: [self::POST => new QuoteSource('"><script>', 'https://example.com/a"onmouseover="x')],
        );

        $html = $this->renderer()->toHtml($this->doc([
            $this->paragraph([['type' => 'mention', 'attrs' => ['id' => self::ACCOUNT]]]),
            ['type' => 'postQuote', 'attrs' => ['postId' => self::POST], 'content' => [$this->paragraph([$this->text('x')])]],
        ]), $context);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('"onmouseover', $html);
        $this->assertStringContainsString('@&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_a_stored_document_with_unknown_or_forbidden_parts_shows_only_what_the_schema_knows(): void
    {
        $html = $this->renderer()->toHtml($this->doc([
            ['type' => 'iframe', 'attrs' => ['src' => 'https://example.com']],
            $this->paragraph([
                $this->text('a', [['type' => 'underline'], ['type' => 'bold']]),
                $this->text('b', [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]),
                ['type' => 'html', 'text' => '<script>alert(1)</script>'],
            ]),
        ]), $this->context());

        $this->assertSame('<p><strong>a</strong>b</p>', $html);
    }

    public function test_a_mention_of_an_account_that_is_gone_and_a_quote_the_reader_cannot_see(): void
    {
        $html = $this->renderer()->toHtml($this->doc([
            $this->paragraph([['type' => 'mention', 'attrs' => ['id' => '00000000-0000-4000-8000-000000000000']]]),
            ['type' => 'postQuote', 'attrs' => ['postId' => '00000000-0000-4000-8000-000000000001'], 'content' => [$this->paragraph([$this->text('x')])]],
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE]],
        ]), new RenderContext);

        $this->assertSame(
            '<p><span class="forum-mention" data-account-id="00000000-0000-4000-8000-000000000000">@cuenta eliminada</span></p>'
            .'<blockquote class="forum-quote"><p class="forum-quote__source">Cita</p><p>x</p></blockquote>',
            $html,
        );
    }

    private function renderer(): DocumentRenderer
    {
        return new DocumentRenderer(DocumentSchema::core());
    }

    private function context(): RenderContext
    {
        return new RenderContext(
            accountNames: [self::ACCOUNT => 'Ana Pérez'],
            quotes: [self::POST => new QuoteSource('Luis', 'https://example.com/foro/m/1')],
            imageUrl: static fn (string $path): string => 'https://cdn.example.com/'.$path,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fullDocument(): array
    {
        return $this->doc([
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [$this->text('Crónica')]],
            $this->paragraph([
                $this->text('Negrita', [['type' => 'bold']]),
                $this->text(' cursiva', [['type' => 'italic'], ['type' => 'strike']]),
                ['type' => 'hardBreak'],
                $this->text('código', [['type' => 'code']]),
                $this->text(' enlace', [['type' => 'link', 'attrs' => ['href' => 'https://example.com/a?b=1&c=2']]]),
                $this->text(' '),
                ['type' => 'mention', 'attrs' => ['id' => self::ACCOUNT]],
            ]),
            ['type' => 'blockquote', 'content' => [$this->paragraph([$this->text('Cita libre')])]],
            ['type' => 'postQuote', 'attrs' => ['postId' => self::POST], 'content' => [$this->paragraph([$this->text('Lo que dijo')])]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    $this->paragraph([$this->text('Uno')]),
                    ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [
                        ['type' => 'listItem', 'content' => [$this->paragraph([$this->text('Tres')])]],
                    ]],
                ]],
            ]],
            ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [$this->text("echo 1;\necho 2;")]],
            ['type' => 'horizontalRule'],
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE, 'alt' => 'Mapa']],
            ['type' => 'paragraph'],
        ]);
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
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function paragraph(array $content): array
    {
        return ['type' => 'paragraph', 'content' => $content];
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return array<string, mixed>
     */
    private function text(string $text, array $marks = []): array
    {
        return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }
}
