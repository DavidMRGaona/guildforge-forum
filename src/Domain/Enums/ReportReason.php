<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * Why someone reports a post: a closed list, with an optional note. The values are stored.
 */
enum ReportReason: string
{
    case Spam = 'spam';
    case Offensive = 'offensive';
    case OffTopic = 'off_topic';
    case PersonalData = 'personal_data';
    case Other = 'other';
}
