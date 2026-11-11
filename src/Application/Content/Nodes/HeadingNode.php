<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\Rendering\RenderContext;

/**
 * Section headings inside a post. Level 1 belongs to the page (the discussion title).
 */
final class HeadingNode extends AbstractNodeType
{
    public const array LEVELS = [2, 3, 4];

    public function name(): string
    {
        return 'heading';
    }

    public function content(): array
    {
        return ['inline'];
    }

    public function allowsMarks(): bool
    {
        return true;
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, ['level'], $path);
        Attributes::intIn($attributes, 'level', self::LEVELS, $path);
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        $level = Attributes::int($attributes, 'level') ?? self::LEVELS[0];

        return "<h{$level}>".implode('', $children)."</h{$level}>";
    }
}
