<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Rendering\RenderContext;

/**
 * A quotation from anywhere (a book, a rule). Quoting a forum post is a postQuote.
 */
final class BlockquoteNode extends AbstractNodeType
{
    public function name(): string
    {
        return 'blockquote';
    }

    public function content(): array
    {
        return ['block'];
    }

    public function requiresContent(): bool
    {
        return true;
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        return '<blockquote>'.implode('', $children).'</blockquote>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        return QuotedText::prefix(implode("\n", $children));
    }
}
