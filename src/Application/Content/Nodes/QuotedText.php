<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

/**
 * Quotations in plain text, as mail clients show them: every line after "> ".
 */
final class QuotedText
{
    public static function prefix(string $text): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => rtrim('> '.$line),
            explode("\n", $text),
        ));
    }
}
