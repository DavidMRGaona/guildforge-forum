<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Marks;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\Contracts\MarkTypeInterface;

/**
 * A mark without attributes that wraps text in one element: bold, italic, strike, inline code.
 */
final readonly class SimpleMark implements MarkTypeInterface
{
    public function __construct(
        private string $name,
        private string $element,
    ) {}

    public static function bold(): self
    {
        return new self('bold', 'strong');
    }

    public static function italic(): self
    {
        return new self('italic', 'em');
    }

    public static function strike(): self
    {
        return new self('strike', 's');
    }

    public static function code(): self
    {
        return new self('code', 'code');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, [], $path);
    }

    public function wrapHtml(array $attributes, string $html): string
    {
        return "<{$this->element}>{$html}</{$this->element}>";
    }
}
