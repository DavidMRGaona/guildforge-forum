<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * Who may have characters and write as them (the forum setting `characters_access`).
 */
enum CharacterAccessMode: string
{
    /** Characters are off: nobody creates them or writes as them; posts already written keep showing theirs */
    case Nobody = 'nobody';

    /** Any signed-in account */
    case Everyone = 'everyone';

    /** Only accounts holding forum:characters.use */
    case Permission = 'permission';

    public function label(): string
    {
        return __("forum::messages.settings.characters_access_options.{$this->value}");
    }
}
