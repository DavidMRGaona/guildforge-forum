<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\Contracts\NodeTypeInterface;
use Modules\Forum\Application\Content\NodeGroup;
use Modules\Forum\Application\Content\NodeLimit;
use Modules\Forum\Application\Content\Rendering\RenderContext;

/**
 * Defaults for node types: a block leaf without attributes, marks or limit, whose text is its
 * children's (blocks one per line).
 */
abstract class AbstractNodeType implements NodeTypeInterface
{
    public function group(): NodeGroup
    {
        return NodeGroup::Block;
    }

    public function content(): ?array
    {
        return null;
    }

    public function requiresContent(): bool
    {
        return false;
    }

    public function allowsMarks(): bool
    {
        return false;
    }

    public function isSubstantial(): bool
    {
        return false;
    }

    public function limit(): ?NodeLimit
    {
        return null;
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, [], $path);
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        // Blocks one per line; text and inline nodes run together
        return implode(in_array(NodeGroup::Block->value, $this->content() ?? [], true) ? "\n" : '', $children);
    }
}
