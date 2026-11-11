<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * How a person follows a discussion.
 */
enum FollowLevel: string
{
    /** Every reply reaches them */
    case Following = 'following';

    /** Only mentions and quotes of their posts */
    case Normal = 'normal';

    /** Nothing about it, mentions included */
    case Muted = 'muted';
}
