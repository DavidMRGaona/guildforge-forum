<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Rendering;

/**
 * Escaping for the post renderer: every piece of text and every attribute value goes through it.
 */
final class Html
{
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
