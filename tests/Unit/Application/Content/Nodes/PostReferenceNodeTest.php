<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Application\Content\Nodes;

use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Application\Content\DocumentSchema;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A reference to another post keeps only the post's id (and, from the editor, a label it shows
 * while writing). It is a leaf inside text, and leaves nothing in the plain text: a number
 * stored there would be wrong once posts are moved and renumbered.
 */
final class PostReferenceNodeTest extends TestCase
{
    private const string POST = '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';

    public function test_a_reference_inside_a_paragraph_is_accepted(): void
    {
        $raw = $this->withReference(['postId' => self::POST]);

        $document = DocumentSchema::core()->validate($raw);

        $this->assertSame($raw, $document->toArray());
        $this->assertSame([self::POST], $document->referencedPostIds());
    }

    public function test_the_editor_label_is_accepted(): void
    {
        $raw = $this->withReference(['postId' => self::POST, 'label' => '#12']);

        $this->assertSame($raw, DocumentSchema::core()->validate($raw)->toArray());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('brokenReferences')]
    public function test_a_broken_reference_is_refused(array $attributes): void
    {
        $this->expectException(InvalidDocumentException::class);

        DocumentSchema::core()->validate($this->withReference($attributes));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function brokenReferences(): array
    {
        return [
            'no post' => [[]],
            'not a uuid' => [['postId' => '12']],
            'unknown attribute' => [['postId' => self::POST, 'number' => 12]],
            'label too long' => [['postId' => self::POST, 'label' => '#1234567890123']],
            'label not text' => [['postId' => self::POST, 'label' => 12]],
        ];
    }

    public function test_it_is_refused_as_a_block(): void
    {
        $this->expectException(InvalidDocumentException::class);

        DocumentSchema::core()->validate(['type' => 'doc', 'content' => [['type' => 'postReference', 'attrs' => ['postId' => self::POST]]]]);
    }

    public function test_more_than_the_limit_is_refused(): void
    {
        $references = array_fill(0, DocumentLimits::MAX_REFERENCES + 1, ['type' => 'postReference', 'attrs' => ['postId' => self::POST]]);

        try {
            DocumentSchema::core()->validate(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $references]]]);
            $this->fail('More references than the limit were accepted');
        } catch (InvalidDocumentException $exception) {
            $this->assertSame(InvalidDocumentException::TOO_MANY_REFERENCES, $exception->reason);
        }
    }

    public function test_it_leaves_nothing_in_the_plain_text(): void
    {
        $schema = DocumentSchema::core();
        $raw = $this->withReference(['postId' => self::POST, 'label' => '#12']);

        $this->assertSame('Mira , dice', (new DocumentRenderer($schema))->toText($schema->validate($raw)->toArray(), new RenderContext));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withReference(array $attributes): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Mira '],
            ['type' => 'postReference', 'attrs' => $attributes],
            ['type' => 'text', 'text' => ', dice'],
        ]]]];
    }
}
