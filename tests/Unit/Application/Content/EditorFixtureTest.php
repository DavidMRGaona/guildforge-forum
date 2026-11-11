<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Application\Content;

use Modules\Forum\Application\Content\DocumentSchema;
use PHPUnit\Framework\TestCase;

/**
 * tests/fixtures/editor-document.json is what the TipTap editor produces for every core node
 * and mark (tests/js/editor/schema.spec.ts checks it on that side), so the server's schema
 * and the editor cannot drift apart unnoticed.
 */
final class EditorFixtureTest extends TestCase
{
    public function test_the_document_the_editor_produces_passes_the_server_schema(): void
    {
        $raw = json_decode((string) file_get_contents(__DIR__.'/../../../fixtures/editor-document.json'), true, flags: JSON_THROW_ON_ERROR);

        $document = DocumentSchema::core()->validate($raw);

        $this->assertSame($raw, $document->toArray());
        $this->assertSame(['9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6'], $document->mentionedAccountIds());
        $this->assertSame(['1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f'], $document->quotedPostIds());
    }
}
