<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Application\Content;

use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Application\Content\ReferenceLinking;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * «#3» written as a word of its own becomes a reference to post 3 of the discussion, when the
 * discussion has a post 3 its author may read. Code, links and quotations of other posts keep
 * their text as it is.
 */
final class ReferenceLinkingTest extends TestCase
{
    private const string POST_3 = '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';

    /** @var list<list<int>> */
    private array $asked = [];

    public function test_a_number_becomes_a_reference_between_its_words(): void
    {
        $this->assertSame(
            $this->doc([$this->paragraph([$this->text('Mira '), $this->reference(), $this->text(' ahora')])]),
            $this->link($this->doc([$this->paragraph([$this->text('Mira #3 ahora')])])),
        );
    }

    #[DataProvider('convertedAlone')]
    public function test_it_is_converted_next_to_punctuation(string $before, string $after): void
    {
        $content = array_values(array_filter([
            $before === '' ? null : $this->text($before),
            $this->reference(),
            $after === '' ? null : $this->text($after),
        ]));

        $this->assertSame($this->doc([$this->paragraph($content)]), $this->link($this->doc([$this->paragraph([$this->text($before.'#3'.$after)])])));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function convertedAlone(): array
    {
        return [
            'alone' => ['', ''],
            'in brackets' => ['(', ')'],
            'before a full stop' => ['', '.'],
            'before a comma' => ['Mira ', ', dice'],
            'in a question' => ['¿', '?'],
        ];
    }

    #[DataProvider('keptAsText')]
    public function test_it_stays_text_unless_it_is_a_word_of_its_own(string $text): void
    {
        $document = $this->doc([$this->paragraph([$this->text($text)])]);

        $this->assertSame($document, $this->link($document));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keptAsText(): array
    {
        return [
            'after a letter' => ['abc#3'],
            'after a slash' => ['/#3'],
            'before a letter' => ['#3a'],
            'after another hash' => ['##3'],
            'with a leading zero' => ['#03'],
            'zero' => ['#0'],
            'a number the discussion lacks' => ['#99'],
        ];
    }

    public function test_code_and_links_keep_their_text(): void
    {
        $document = $this->doc([$this->paragraph([
            $this->text('#3', [['type' => 'code']]),
            $this->text(' y '),
            $this->text('#3', [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]),
        ])]);

        $this->assertSame($document, $this->link($document));
    }

    public function test_code_blocks_and_quotations_keep_their_text(): void
    {
        $document = $this->doc([
            ['type' => 'codeBlock', 'content' => [$this->text('#3')]],
            ['type' => 'postQuote', 'attrs' => ['postId' => self::POST_3], 'content' => [$this->paragraph([$this->text('Como dije en #3')])]],
        ]);

        $this->assertSame($document, $this->link($document));
        $this->assertSame([], $this->asked);
    }

    public function test_headings_free_quotes_lists_and_asides_are_converted(): void
    {
        $linked = $this->link($this->doc([
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [$this->text('#3')]],
            ['type' => 'blockquote', 'content' => [$this->paragraph([$this->text('#3')])]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$this->paragraph([$this->text('#3')])]]]],
            ['type' => 'ooc', 'content' => [$this->paragraph([$this->text('#3')])]],
        ]));

        $this->assertSame($this->doc([
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [$this->reference()]],
            ['type' => 'blockquote', 'content' => [$this->paragraph([$this->reference()])]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$this->paragraph([$this->reference()])]]]],
            ['type' => 'ooc', 'content' => [$this->paragraph([$this->reference()])]],
        ]), $linked);
    }

    public function test_the_runs_next_to_it_decide_its_boundaries(): void
    {
        $document = $this->doc([
            $this->paragraph([$this->text('abc', [['type' => 'bold']]), $this->text('#3')]),
            $this->paragraph([$this->text('#3'), $this->text('a', [['type' => 'bold']])]),
        ]);

        $this->assertSame($document, $this->link($document));
    }

    public function test_a_node_next_to_it_is_a_boundary(): void
    {
        $this->assertSame(
            $this->doc([$this->paragraph([['type' => 'hardBreak'], $this->reference()])]),
            $this->link($this->doc([$this->paragraph([['type' => 'hardBreak'], $this->text('#3')])])),
        );
    }

    public function test_the_marks_of_the_run_stay_on_the_text_around_it(): void
    {
        $bold = [['type' => 'bold']];

        $this->assertSame(
            $this->doc([$this->paragraph([$this->text('Mira ', $bold), $this->reference(), $this->text(' ya', $bold)])]),
            $this->link($this->doc([$this->paragraph([$this->text('Mira #3 ya', $bold)])])),
        );
    }

    public function test_numbers_are_looked_up_once_for_the_whole_document(): void
    {
        $this->link($this->doc([$this->paragraph([$this->text('#3 y #3 y #99')]), $this->paragraph([$this->text('#3')])]));

        $this->assertSame([[3, 99]], $this->asked);
    }

    public function test_no_lookup_without_numbers(): void
    {
        $this->link($this->doc([$this->paragraph([$this->text('Sin referencias')])]));

        $this->assertSame([], $this->asked);
    }

    public function test_at_most_the_limit_of_numbers_is_looked_up(): void
    {
        $text = implode(' ', array_map(static fn (int $number): string => '#'.$number, range(1, 100)));

        $this->link(self::doc([self::paragraph([self::text($text)])]));

        $this->assertSame([range(1, DocumentLimits::MAX_REFERENCES)], $this->asked);
    }

    public function test_a_long_text_full_of_numbers_takes_linear_time(): void
    {
        // About as long as a post may be: copying what comes before each number would take minutes
        $text = str_repeat('#3 ', 80_000);
        $started = microtime(true);

        $linked = $this->link(self::doc([self::paragraph([self::text($text)])]));

        $this->assertLessThan(2.0, microtime(true) - $started);
        // 30 references, the spaces between them, and the rest of the text as it was
        $this->assertCount(DocumentLimits::MAX_REFERENCES * 2, $linked['content'][0]['content']);
    }

    public function test_only_up_to_the_limit_are_converted(): void
    {
        $text = implode(' ', array_map(static fn (int $number): string => '#'.$number, range(1, DocumentLimits::MAX_REFERENCES + 1)));
        $ids = [];
        foreach (range(1, DocumentLimits::MAX_REFERENCES + 1) as $number) {
            $ids[$number] = sprintf('00000000-0000-4000-8000-%012d', $number);
        }

        $linked = ReferenceLinking::link($this->doc([$this->paragraph([$this->text($text)])]), static fn (array $numbers): array => $ids);

        $content = $linked['content'][0]['content'];
        $this->assertCount(DocumentLimits::MAX_REFERENCES, array_filter($content, static fn (array $node): bool => $node['type'] === 'postReference'));
        $this->assertSame(['type' => 'text', 'text' => ' #'.(DocumentLimits::MAX_REFERENCES + 1)], end($content));
    }

    public function test_references_already_there_count_towards_the_limit_and_lose_their_label(): void
    {
        $existing = array_fill(0, DocumentLimits::MAX_REFERENCES, ['type' => 'postReference', 'attrs' => ['postId' => self::POST_3, 'label' => '#3']]);

        $linked = $this->link($this->doc([$this->paragraph([...$existing, $this->text(' #3')])]));

        $content = $linked['content'][0]['content'];
        $this->assertSame($this->reference(), $content[0]);
        $this->assertSame($this->text(' #3'), end($content));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function link(array $document): array
    {
        return ReferenceLinking::link($document, function (array $numbers): array {
            $this->asked[] = $numbers;

            return in_array(3, $numbers, true) ? [3 => self::POST_3] : [];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function reference(): array
    {
        return ['type' => 'postReference', 'attrs' => ['postId' => self::POST_3]];
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
