<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\Rendering\Html;
use Modules\Forum\Application\Content\Rendering\RenderContext;

/**
 * Preformatted text, without marks or mentions. The language is only a label for styling.
 */
final class CodeBlockNode extends AbstractNodeType
{
    public const string LANGUAGE_PATTERN = '/^[a-z0-9][a-z0-9+#.-]{0,29}\z/';

    public function name(): string
    {
        return 'codeBlock';
    }

    public function content(): array
    {
        return ['text'];
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, ['language'], $path);
        Attributes::optionalMatching($attributes, 'language', self::LANGUAGE_PATTERN, $path);
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        $language = Attributes::string($attributes, 'language');

        return '<pre><code'.($language === null ? '' : ' class="language-'.Html::escape($language).'"').'>'.implode('', $children).'</code></pre>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        return implode('', $children);
    }
}
