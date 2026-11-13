<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Conversations;

use Modules\Forum\Application\Content\DocumentSchema;
use Modules\Forum\Application\Content\Nodes\BlockquoteNode;
use Modules\Forum\Application\Content\Nodes\BulletListNode;
use Modules\Forum\Application\Content\Nodes\CodeBlockNode;
use Modules\Forum\Application\Content\Nodes\HardBreakNode;
use Modules\Forum\Application\Content\Nodes\HeadingNode;
use Modules\Forum\Application\Content\Nodes\HorizontalRuleNode;
use Modules\Forum\Application\Content\Nodes\ListItemNode;
use Modules\Forum\Application\Content\Nodes\OrderedListNode;
use Modules\Forum\Application\Content\Nodes\ParagraphNode;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\ValueObjects\PostBody;

/**
 * The documents of private messages: the post editor's text and formatting only. No images
 * (uploaded or from other sites), mentions, quotes of posts, dice rolls, asides or embeds: an
 * image would reach the media library, which the panel shows, and the rest point outside the
 * conversation. The schema refuses them, whatever the page sends.
 */
final readonly class ConversationDocuments
{
    private DocumentSchema $schema;

    private DocumentRenderer $renderer;

    public function __construct()
    {
        $this->schema = new DocumentSchema(
            [
                new ParagraphNode,
                new HeadingNode,
                new BlockquoteNode,
                new BulletListNode,
                new OrderedListNode,
                new ListItemNode,
                new CodeBlockNode,
                new HorizontalRuleNode,
                new HardBreakNode,
            ],
            DocumentSchema::coreMarks(),
        );
        $this->renderer = new DocumentRenderer($this->schema);
    }

    /**
     * A body from what the editor sent, refused as it came when anything in it is not allowed.
     *
     * @throws InvalidDocumentException
     */
    public function fromInput(mixed $raw): PostBody
    {
        $document = $this->schema->validate($raw);

        $text = $this->renderer->toText($document->toArray(), new RenderContext);
        if (mb_strlen($text) > PostBody::MAX_PLAIN_TEXT_LENGTH) {
            throw InvalidDocumentException::because(InvalidDocumentException::TOO_LONG, '', 'The message is too long');
        }

        return new PostBody($document->toArray(), $text);
    }

    public function toHtml(PostBody $body): string
    {
        return $this->renderer->toHtml($body->document, new RenderContext);
    }
}
