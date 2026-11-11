<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Application\Content\NodeLimit;
use Modules\Forum\Application\Content\Rendering\Html;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;

/**
 * A quotation of a forum post (all of it or the part selected). It keeps only the post's id:
 * who wrote it and the link to it are looked up when shown, and only for readers who may see
 * the original, so a quote can neither be attributed to someone else nor reveal where a
 * private post lives.
 */
final class PostQuoteNode extends AbstractNodeType
{
    public function name(): string
    {
        return 'postQuote';
    }

    public function content(): array
    {
        return ['block'];
    }

    public function requiresContent(): bool
    {
        return true;
    }

    public function limit(): NodeLimit
    {
        return new NodeLimit(DocumentLimits::MAX_QUOTES, InvalidDocumentException::TOO_MANY_QUOTES);
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, ['postId'], $path);
        Attributes::uuid($attributes, 'postId', $path);
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        $source = $context->quote(Attributes::string($attributes, 'postId') ?? '');
        $heading = $source?->authorName === null
            ? Html::escape(__('forum::messages.content.quote'))
            : Html::escape(__('forum::messages.content.quote_by', ['name' => $source->authorName]));

        if ($source?->url !== null) {
            $heading = '<a href="'.Html::escape($source->url).'">'.$heading.'</a>';
        }

        return '<blockquote class="forum-quote"><p class="forum-quote__source">'.$heading.'</p>'.implode('', $children).'</blockquote>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        return QuotedText::prefix(implode("\n", $children));
    }
}
