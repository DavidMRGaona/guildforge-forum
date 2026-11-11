<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Rendering\RenderContext;

final class ParagraphNode extends AbstractNodeType
{
    public function name(): string
    {
        return 'paragraph';
    }

    public function content(): array
    {
        return ['inline'];
    }

    public function allowsMarks(): bool
    {
        return true;
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        return '<p>'.implode('', $children).'</p>';
    }
}
