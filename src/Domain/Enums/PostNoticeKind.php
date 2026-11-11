<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * Why a person hears about a post. A person hears once per post, for the first reason in this
 * order: being mentioned, being quoted, following the discussion or its category.
 */
enum PostNoticeKind: string
{
    case Mention = 'mention';

    case Quote = 'quote';

    case Reply = 'reply';

    case NewDiscussion = 'new_discussion';
}
