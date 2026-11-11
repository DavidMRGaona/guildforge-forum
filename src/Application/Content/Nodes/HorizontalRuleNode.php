<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Rendering\RenderContext;

final class HorizontalRuleNode extends AbstractNodeType
{
    public function name(): string
    {
        return 'horizontalRule';
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        return '<hr>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        return '';
    }
}
