<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * Who sees the forum on the site (the forum setting `access`).
 */
enum ForumAccessMode: string
{
    /** Only whoever holds forum:forum.preview (admins hold every permission); for anyone else it does not exist */
    case Preview = 'preview';

    /** The whole site, under each category's own rules */
    case Open = 'open';

    public function label(): string
    {
        return __("forum::messages.settings.access_options.{$this->value}");
    }
}
