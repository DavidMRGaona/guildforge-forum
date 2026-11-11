<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Rendering\RenderContext;

final class ListItemNode extends AbstractNodeType
{
    public function name(): string
    {
        return 'listItem';
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
        return '<li>'.implode('', $children).'</li>';
    }
}
