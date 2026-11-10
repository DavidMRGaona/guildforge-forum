<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\ValueObjects\PostBody;
use PHPUnit\Framework\TestCase;

final class PostBodyTest extends TestCase
{
    public function test_it_keeps_the_document_and_its_trimmed_plain_text(): void
    {
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hola']]]]];

        $body = new PostBody($document, " Hola \n");

        $this->assertSame($document, $body->document);
        $this->assertSame('Hola', $body->plainText);
    }

    public function test_the_document_is_a_tiptap_document(): void
    {
        $this->expectException(InvalidPostException::class);

        new PostBody(['type' => 'paragraph'], 'Hola');
    }

    public function test_the_plain_text_has_a_limit(): void
    {
        $this->expectException(InvalidPostException::class);

        new PostBody(['type' => 'doc', 'content' => []], str_repeat('a', PostBody::MAX_PLAIN_TEXT_LENGTH + 1));
    }

    public function test_a_document_without_text_is_allowed(): void
    {
        // A post may hold only a dice roll or an image: what it may contain is decided by the editor's schema
        $this->assertSame('', (new PostBody(['type' => 'doc', 'content' => []], ''))->plainText);
    }
}
