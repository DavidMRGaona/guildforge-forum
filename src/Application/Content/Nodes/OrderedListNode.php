<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\Rendering\RenderContext;

final class OrderedListNode extends AbstractNodeType
{
    public const int MAX_START = 100_000;

    public function name(): string
    {
        return 'orderedList';
    }

    public function content(): array
    {
        return ['listItem'];
    }

    public function requiresContent(): bool
    {
        return true;
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, ['start'], $path);
        Attributes::optionalIntBetween($attributes, 'start', 1, self::MAX_START, $path);
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        $start = $this->start($attributes);

        return ($start === 1 ? '<ol>' : "<ol start=\"{$start}\">").implode('', $children).'</ol>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        $start = $this->start($attributes);

        return implode("\n", array_map(
            static fn (int $index, string $item): string => ($start + $index).'. '.$item,
            array_keys($children),
            $children,
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function start(array $attributes): int
    {
        return Attributes::int($attributes, 'start') ?? 1;
    }
}
