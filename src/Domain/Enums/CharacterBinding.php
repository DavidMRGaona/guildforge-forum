<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * Whether a character tied to a category can be used there (forum#108, forum#109). A paused
 * one stays tied: it is never moved or untied on its own, and comes back when the reason goes.
 */
enum CharacterBinding: string
{
    /** Tied to no category: usable anywhere its owner writes */
    case Free = 'free';

    /** Tied to a category its owner may use it in */
    case Active = 'active';

    /** Paused: its owner is no longer in the campaign whose space it is tied to */
    case LeftCampaign = 'left_campaign';

    /** Paused: the campaign of its space was taken down or deleted */
    case CampaignGone = 'campaign_gone';

    /** Paused: its owner may no longer read the category */
    case NoAccess = 'no_access';

    /** Paused: the category was deleted */
    case CategoryGone = 'category_gone';

    public function isPaused(): bool
    {
        return $this !== self::Free && $this !== self::Active;
    }
}
