<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Application\Content;

use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Application\Content\DocumentSchema;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The closed list of nodes and marks a post may hold. Anything else is refused as it came,
 * with a reason and where it was found; nothing is repaired.
 */
final class DocumentSchemaTest extends TestCase
{
    private const string IMAGE = 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp';

    private const string COPY = 'forum/external/2026/11/7c1e2d3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f.png';

    private const string REMOTE = 'https://i.imgur.com/AbC123.png';

    private const string ACCOUNT = '9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6';

    private const string POST = '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';

    public function test_a_document_with_every_core_node_and_mark_is_accepted(): void
    {
        $raw = self::doc([
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [self::text('Crónica')]],
            self::paragraph([
                self::text('Negrita', [['type' => 'bold']]),
                self::text(' cursiva', [['type' => 'italic'], ['type' => 'strike']]),
                ['type' => 'hardBreak'],
                self::text('código', [['type' => 'code']]),
                self::text(' enlace', [['type' => 'link', 'attrs' => ['href' => 'https://example.com/a?b=1']]]),
                ['type' => 'mention', 'attrs' => ['id' => self::ACCOUNT]],
            ]),
            ['type' => 'blockquote', 'content' => [self::paragraph([self::text('Cita libre')])]],
            ['type' => 'postQuote', 'attrs' => ['postId' => self::POST], 'content' => [self::paragraph([self::text('Cita de un mensaje')])]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    self::paragraph([self::text('Uno')]),
                    ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [
                        ['type' => 'listItem', 'content' => [self::paragraph([self::text('Tres')])]],
                    ]],
                ]],
            ]],
            ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [self::text("echo 1;\necho 2;")]],
            ['type' => 'horizontalRule'],
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE, 'alt' => 'Mapa de la mazmorra']],
            ['type' => 'paragraph'],
        ]);

        $document = DocumentSchema::core()->validate($raw);

        $this->assertSame($raw, $document->toArray());
        $this->assertSame([['id' => self::ACCOUNT]], $document->attributesOf('mention'));
        $this->assertSame([self::ACCOUNT], $document->mentionedAccountIds());
        $this->assertSame([self::IMAGE], $document->imagePaths());
        $this->assertSame([self::POST], $document->quotedPostIds());
    }

    public function test_optional_attributes_may_be_null_or_left_out(): void
    {
        $document = DocumentSchema::core()->validate(self::doc([
            ['type' => 'codeBlock', 'attrs' => ['language' => null], 'content' => [self::text('x')]],
            ['type' => 'codeBlock', 'content' => [self::text('y')]],
            ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [self::paragraph([self::text('a')])]]]],
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE, 'alt' => null]],
            // As the editor writes an upload: every attribute, the copy empty
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE, 'path' => null, 'alt' => null]],
        ]));

        $this->assertSame([self::IMAGE], $document->imagePaths());
    }

    public function test_an_image_from_another_site_keeps_its_address_and_the_forum_copy(): void
    {
        $document = DocumentSchema::core()->validate(self::doc([
            ['type' => 'image', 'attrs' => ['origin' => 'external', 'src' => self::REMOTE, 'path' => self::COPY, 'alt' => 'Mapa']],
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE]],
            ['type' => 'image', 'attrs' => ['origin' => 'external', 'src' => self::REMOTE, 'path' => self::COPY]],
        ]));

        $this->assertSame([self::COPY, self::IMAGE], $document->imagePaths());
        $this->assertSame([['url' => self::REMOTE, 'path' => self::COPY]], $document->externalImages());
    }

    public function test_repeated_mentions_images_and_quotes_are_listed_once(): void
    {
        $mention = ['type' => 'mention', 'attrs' => ['id' => self::ACCOUNT]];
        $document = DocumentSchema::core()->validate(self::doc([
            self::paragraph([$mention, self::text(' y '), $mention]),
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE]],
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE]],
        ]));

        $this->assertSame([self::ACCOUNT], $document->mentionedAccountIds());
        $this->assertSame([self::IMAGE], $document->imagePaths());
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'not an array' => ['<p>hola</p>', InvalidDocumentException::NOT_A_DOCUMENT, ''];
        yield 'a list' => [[['type' => 'doc']], InvalidDocumentException::NOT_A_DOCUMENT, ''];
        yield 'root is not a doc' => [self::paragraph([self::text('x')]), InvalidDocumentException::NOT_A_DOCUMENT, ''];
        yield 'doc without content' => [['type' => 'doc'], InvalidDocumentException::MISSING_CONTENT, ''];
        yield 'doc with attributes' => [['type' => 'doc', 'attrs' => ['x' => 1], 'content' => [self::paragraph([self::text('x')])]], InvalidDocumentException::INVALID_ATTRIBUTE, ''];
        yield 'only empty paragraphs' => [self::doc([['type' => 'paragraph'], self::paragraph([self::text('   ')])]), InvalidDocumentException::EMPTY, ''];
        yield 'unknown node' => [self::doc([['type' => 'iframe', 'attrs' => ['src' => 'https://example.com']]]), InvalidDocumentException::UNKNOWN_NODE, 'content.0'];
        yield 'html node' => [self::doc([self::paragraph([['type' => 'html', 'text' => '<script>alert(1)</script>']])]), InvalidDocumentException::UNKNOWN_NODE, 'content.0.content.0'];
        yield 'type is not a string' => [self::doc([['type' => ['paragraph']]]), InvalidDocumentException::UNKNOWN_NODE, 'content.0'];
        yield 'unknown key on a node' => [self::doc([['type' => 'paragraph', 'html' => '<b>x</b>', 'content' => [self::text('x')]]]), InvalidDocumentException::UNEXPECTED_KEY, 'content.0'];
        yield 'content is an object' => [self::doc([['type' => 'paragraph', 'content' => ['a' => self::text('x')]]]), InvalidDocumentException::NOT_ALLOWED_HERE, 'content.0'];
        yield 'text at the top level' => [self::doc([self::text('suelto')]), InvalidDocumentException::NOT_ALLOWED_HERE, 'content.0'];
        yield 'block inside a paragraph' => [self::doc([self::paragraph([['type' => 'horizontalRule']])]), InvalidDocumentException::NOT_ALLOWED_HERE, 'content.0.content.0'];
        yield 'paragraph straight inside a list' => [self::doc([['type' => 'bulletList', 'content' => [self::paragraph([self::text('x')])]]]), InvalidDocumentException::NOT_ALLOWED_HERE, 'content.0.content.0'];
        yield 'empty list' => [self::doc([['type' => 'bulletList', 'content' => []]]), InvalidDocumentException::MISSING_CONTENT, 'content.0'];
        yield 'empty list item' => [self::doc([['type' => 'bulletList', 'content' => [['type' => 'listItem']]]]), InvalidDocumentException::MISSING_CONTENT, 'content.0.content.0'];
        yield 'empty quote' => [self::doc([['type' => 'blockquote', 'content' => []]]), InvalidDocumentException::MISSING_CONTENT, 'content.0'];
        yield 'leaf with content' => [self::doc([['type' => 'horizontalRule', 'content' => [self::paragraph([self::text('x')])]]]), InvalidDocumentException::NOT_ALLOWED_HERE, 'content.0'];
        yield 'empty text' => [self::doc([self::paragraph([self::text('')])]), InvalidDocumentException::INVALID_TEXT, 'content.0.content.0'];
        yield 'text that is not a string' => [self::doc([self::paragraph([['type' => 'text', 'text' => 5]])]), InvalidDocumentException::INVALID_TEXT, 'content.0.content.0'];
        yield 'null character' => [self::doc([self::paragraph([self::text("a\0b")])]), InvalidDocumentException::INVALID_TEXT, 'content.0.content.0'];
        yield 'text with content' => [self::doc([self::paragraph([['type' => 'text', 'text' => 'x', 'content' => []]])]), InvalidDocumentException::UNEXPECTED_KEY, 'content.0.content.0'];
        yield 'text with attributes' => [self::doc([self::paragraph([['type' => 'text', 'text' => 'x', 'attrs' => ['class' => 'x']]])]), InvalidDocumentException::UNEXPECTED_KEY, 'content.0.content.0'];
        yield 'unknown mark' => [self::doc([self::paragraph([self::text('x', [['type' => 'underline']])])]), InvalidDocumentException::UNKNOWN_MARK, 'content.0.content.0.marks.0'];
        yield 'repeated mark' => [self::doc([self::paragraph([self::text('x', [['type' => 'bold'], ['type' => 'bold']])])]), InvalidDocumentException::NOT_ALLOWED_HERE, 'content.0.content.0.marks.1'];
        yield 'mark with an unknown attribute' => [self::doc([self::paragraph([self::text('x', [['type' => 'bold', 'attrs' => ['style' => 'color:red']]])])]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0.content.0.marks.0'];
        yield 'mark with an unknown key' => [self::doc([self::paragraph([self::text('x', [['type' => 'bold', 'html' => '<b>']])])]), InvalidDocumentException::UNEXPECTED_KEY, 'content.0.content.0.marks.0'];
        yield 'marks in a code block' => [self::doc([['type' => 'codeBlock', 'content' => [self::text('x', [['type' => 'bold']])]]]), InvalidDocumentException::NOT_ALLOWED_HERE, 'content.0.content.0'];
        yield 'mention in a code block' => [self::doc([['type' => 'codeBlock', 'content' => [['type' => 'mention', 'attrs' => ['id' => self::ACCOUNT]]]]]), InvalidDocumentException::NOT_ALLOWED_HERE, 'content.0.content.0'];
        yield 'marks on a block' => [self::doc([['type' => 'paragraph', 'marks' => [['type' => 'bold']], 'content' => [self::text('x')]]]), InvalidDocumentException::UNEXPECTED_KEY, 'content.0'];
        yield 'heading level 1' => [self::doc([['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [self::text('x')]]]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0'];
        yield 'heading level as text' => [self::doc([['type' => 'heading', 'attrs' => ['level' => '2'], 'content' => [self::text('x')]]]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0'];
        yield 'heading without level' => [self::doc([['type' => 'heading', 'content' => [self::text('x')]]]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0'];
        yield 'heading with another attribute' => [self::doc([['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'center'], 'content' => [self::text('x')]]]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0'];
        yield 'attributes that are a list' => [self::doc([['type' => 'heading', 'attrs' => [2], 'content' => [self::text('x')]]]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0'];
        yield 'ordered list starting at 0' => [self::doc([['type' => 'orderedList', 'attrs' => ['start' => 0], 'content' => [['type' => 'listItem', 'content' => [self::paragraph([self::text('a')])]]]]]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0'];
        yield 'code language with markup' => [self::doc([['type' => 'codeBlock', 'attrs' => ['language' => 'php"><script>'], 'content' => [self::text('x')]]]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0'];
        yield 'link to javascript' => [self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]])])]), InvalidDocumentException::INVALID_LINK, 'content.0.content.0.marks.0'];
        yield 'link to javascript in capitals' => [self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => ' JavaScript:alert(1)']]])])]), InvalidDocumentException::INVALID_LINK, 'content.0.content.0.marks.0'];
        yield 'link to data' => [self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => 'data:text/html;base64,PHNjcmlwdD4=']]])])]), InvalidDocumentException::INVALID_LINK, 'content.0.content.0.marks.0'];
        yield 'relative link' => [self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => '/foro']]])])]), InvalidDocumentException::INVALID_LINK, 'content.0.content.0.marks.0'];
        yield 'protocol-relative link' => [self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => '//example.com']]])])]), InvalidDocumentException::INVALID_LINK, 'content.0.content.0.marks.0'];
        yield 'link without host' => [self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => 'https://']]])])]), InvalidDocumentException::INVALID_LINK, 'content.0.content.0.marks.0'];
        yield 'link with spaces' => [self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => 'https://exa mple.com']]])])]), InvalidDocumentException::INVALID_LINK, 'content.0.content.0.marks.0'];
        yield 'link with credentials' => [self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => 'https://user:pass@example.com']]])])]), InvalidDocumentException::INVALID_LINK, 'content.0.content.0.marks.0'];
        yield 'empty mailto' => [self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => 'mailto:']]])])]), InvalidDocumentException::INVALID_LINK, 'content.0.content.0.marks.0'];
        yield 'link too long' => [self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => 'https://example.com/'.str_repeat('a', 2048)]]])])]), InvalidDocumentException::INVALID_LINK, 'content.0.content.0.marks.0'];
        yield 'link with a target' => [self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'target' => '_self']]])])]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0.content.0.marks.0'];
        yield 'external image' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => 'https://tracker.example.com/pixel.gif']]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'image of another folder' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => 'users/1/avatar.png']]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'image path climbing up' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => 'forum/posts/2026/11/../../../users/a.png']]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'image with an svg file' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.svg']]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'image without origin' => [self::doc([['type' => 'image', 'attrs' => ['src' => self::IMAGE]]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'image from an unknown origin' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'hotlink', 'src' => self::REMOTE]]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'external image without its copy' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'external', 'src' => self::REMOTE]]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'external image whose copy is an upload' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'external', 'src' => self::REMOTE, 'path' => self::IMAGE]]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'external image over http' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'external', 'src' => 'http://i.imgur.com/a.png', 'path' => self::COPY]]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'external image at an address' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'external', 'src' => 'https://169.254.169.254/a.png', 'path' => self::COPY]]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'external image written loosely' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'external', 'src' => 'https://I.IMGUR.com/AbC123.png', 'path' => self::COPY]]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'upload with a copy path' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE, 'path' => self::COPY]]]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0'];
        yield 'upload stored as an external copy' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::COPY]]]), InvalidDocumentException::INVALID_IMAGE, 'content.0'];
        yield 'image alt too long' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE, 'alt' => str_repeat('a', 301)]]]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0'];
        yield 'image with a title' => [self::doc([['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE, 'title' => 'x']]]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0'];
        yield 'image inside a paragraph' => [self::doc([self::paragraph([['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE]]])]), InvalidDocumentException::NOT_ALLOWED_HERE, 'content.0.content.0'];
        yield 'mention without id' => [self::doc([self::paragraph([['type' => 'mention', 'attrs' => []]])]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0.content.0'];
        yield 'mention by name' => [self::doc([self::paragraph([['type' => 'mention', 'attrs' => ['id' => 'admin']]])]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0.content.0'];
        yield 'mention with a label' => [self::doc([self::paragraph([['type' => 'mention', 'attrs' => ['id' => self::ACCOUNT, 'label' => 'Administración']]])]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0.content.0'];
        yield 'quote of a malformed post' => [self::doc([['type' => 'postQuote', 'attrs' => ['postId' => '5'], 'content' => [self::paragraph([self::text('x')])]]]), InvalidDocumentException::INVALID_ATTRIBUTE, 'content.0'];
    }

    #[DataProvider('invalidDocuments')]
    public function test_an_invalid_document_is_refused_with_the_reason_and_where(mixed $raw, string $reason, string $path): void
    {
        try {
            DocumentSchema::core()->validate($raw);
            $this->fail('The document was accepted.');
        } catch (InvalidDocumentException $exception) {
            $this->assertSame($reason, $exception->reason, $exception->getMessage());
            $this->assertSame($path, $exception->path);
        }
    }

    public function test_the_links_people_write_are_accepted(): void
    {
        $hrefs = ['https://example.com', 'http://example.com:8080/a/b?c=d#e', 'mailto:someone@example.com', 'HTTPS://EXAMPLE.COM/MAYÚS', 'https://ejemplo.es/ruta/ñandú'];

        foreach ($hrefs as $href) {
            DocumentSchema::core()->validate(self::doc([self::paragraph([self::text('x', [['type' => 'link', 'attrs' => ['href' => $href]]])])]));
        }

        $this->addToAssertionCount(count($hrefs));
    }

    public function test_a_document_too_large_is_refused_before_it_is_read(): void
    {
        try {
            DocumentSchema::core()->validate(self::doc([self::paragraph([self::text(str_repeat('a', DocumentLimits::MAX_BYTES))])]));
            $this->fail('The document was accepted.');
        } catch (InvalidDocumentException $exception) {
            $this->assertSame(InvalidDocumentException::TOO_LARGE, $exception->reason);
        }
    }

    public function test_a_document_nested_too_deep_is_refused(): void
    {
        $node = self::paragraph([self::text('fondo')]);
        for ($level = 0; $level < DocumentLimits::MAX_DEPTH; $level++) {
            $node = ['type' => 'blockquote', 'content' => [$node]];
        }

        try {
            DocumentSchema::core()->validate(self::doc([$node]));
            $this->fail('The document was accepted.');
        } catch (InvalidDocumentException $exception) {
            $this->assertSame(InvalidDocumentException::TOO_DEEP, $exception->reason);
        }
    }

    public function test_nesting_up_to_the_limit_is_accepted(): void
    {
        // doc > blockquote × (MAX_DEPTH - 3) > paragraph > text
        $node = self::paragraph([self::text('fondo')]);
        for ($level = 0; $level < DocumentLimits::MAX_DEPTH - 3; $level++) {
            $node = ['type' => 'blockquote', 'content' => [$node]];
        }

        DocumentSchema::core()->validate(self::doc([$node]));

        $this->addToAssertionCount(1);
    }

    public function test_a_document_with_too_many_nodes_is_refused(): void
    {
        // Small nodes, so the document stays under the size limit
        $breaks = array_fill(0, DocumentLimits::MAX_NODES, ['type' => 'hardBreak']);

        try {
            DocumentSchema::core()->validate(self::doc([self::paragraph([self::text('x'), ...$breaks])]));
            $this->fail('The document was accepted.');
        } catch (InvalidDocumentException $exception) {
            $this->assertSame(InvalidDocumentException::TOO_MANY_NODES, $exception->reason);
        }
    }

    public function test_more_images_mentions_or_quotes_than_allowed_are_refused(): void
    {
        $cases = [
            InvalidDocumentException::TOO_MANY_IMAGES => array_map(
                static fn (int $i): array => ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => sprintf('forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-%012d.png', $i)]],
                range(1, DocumentLimits::MAX_IMAGES + 1),
            ),
            InvalidDocumentException::TOO_MANY_MENTIONS => [self::paragraph(array_map(
                static fn (int $i): array => ['type' => 'mention', 'attrs' => ['id' => sprintf('9d4f6a2e-1b3c-4d5e-8f70-%012d', $i)]],
                range(1, DocumentLimits::MAX_MENTIONS + 1),
            ))],
            InvalidDocumentException::TOO_MANY_QUOTES => array_map(
                static fn (int $i): array => ['type' => 'postQuote', 'attrs' => ['postId' => sprintf('1c2d3e4f-5a6b-4c7d-8e9f-%012d', $i)], 'content' => [self::paragraph([self::text('x')])]],
                range(1, DocumentLimits::MAX_QUOTES + 1),
            ),
        ];

        foreach ($cases as $reason => $content) {
            try {
                DocumentSchema::core()->validate(self::doc($content));
                $this->fail("{$reason}: the document was accepted.");
            } catch (InvalidDocumentException $exception) {
                $this->assertSame($reason, $exception->reason);
            }
        }
    }

    public function test_the_images_per_post_come_from_the_settings(): void
    {
        $images = array_map(
            static fn (int $i): array => ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => sprintf('forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-%012d.png', $i)]],
            range(1, 3),
        );

        DocumentSchema::core(maxImages: 3)->validate(self::doc($images));

        try {
            DocumentSchema::core(maxImages: 2)->validate(self::doc($images));
            $this->fail('The document was accepted.');
        } catch (InvalidDocumentException $exception) {
            $this->assertSame(InvalidDocumentException::TOO_MANY_IMAGES, $exception->reason);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private static function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private static function paragraph(array $content): array
    {
        return ['type' => 'paragraph', 'content' => $content];
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return array<string, mixed>
     */
    private static function text(string $text, array $marks = []): array
    {
        return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }
}
