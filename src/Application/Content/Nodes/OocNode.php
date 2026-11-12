<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Contracts\OutOfCharacterInterface;
use Modules\Forum\Application\Content\Rendering\Html;
use Modules\Forum\Application\Content\Rendering\RenderContext;

/**
 * An aside out of character: what whoever plays the character says as themselves, inside a
 * post written in character. It looks apart from the story and says so in words, so it does
 * not depend on its colour. Rolls inside it name nobody.
 */
final class OocNode extends AbstractNodeType implements OutOfCharacterInterface
{
    public function name(): string
    {
        return 'ooc';
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
        return '<aside class="forum-ooc"><p class="forum-ooc__label">'.Html::escape(__('forum::messages.content.ooc')).'</p>'
            .implode('', $children).'</aside>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        return '('.__('forum::messages.content.ooc').') '.implode("\n", $children);
    }
}
