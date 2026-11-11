<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Rendering\RenderContext;

final class BulletListNode extends AbstractNodeType
{
    public function name(): string
    {
        return 'bulletList';
    }

    public function content(): array
    {
        return ['listItem'];
    }

    public function requiresContent(): bool
    {
        return true;
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        return '<ul>'.implode('', $children).'</ul>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        return implode("\n", array_map(static fn (string $item): string => '- '.$item, $children));
    }
}
