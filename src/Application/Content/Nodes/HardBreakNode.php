<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\NodeGroup;
use Modules\Forum\Application\Content\Rendering\RenderContext;

final class HardBreakNode extends AbstractNodeType
{
    public function name(): string
    {
        return 'hardBreak';
    }

    public function group(): NodeGroup
    {
        return NodeGroup::Inline;
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        return '<br>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        return "\n";
    }
}
