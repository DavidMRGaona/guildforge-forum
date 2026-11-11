<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Mail\DTOs\MailSubjectDTO;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;

/**
 * What the forum's notices are about, for the core's mutes: a discussion (replies, mentions,
 * quotes, likes) or a category (new discussions, a campaign space). Muting one from a mail, the
 * profile or the forum's own «Silenciar» holds back every optional notice about it, on the site
 * and by mail.
 */
final class ForumMailSubjects
{
    public const string DISCUSSION = 'forum.discussion';

    public const string CATEGORY = 'forum.category';

    public static function discussion(Discussion $discussion): MailSubjectDTO
    {
        return new MailSubjectDTO(self::DISCUSSION, $discussion->id()->value, $discussion->title()->value, 'forum::messages.notices.subjects.discussion');
    }

    public static function category(Category $category): MailSubjectDTO
    {
        return new MailSubjectDTO(self::CATEGORY, $category->id()->value, $category->name(), 'forum::messages.notices.subjects.category');
    }
}
