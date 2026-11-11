<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\Mail\DTOs\MailCategoryDTO;

/**
 * The forum's mail categories, in the «Foro» section of the profile. Their keys are stored with
 * each person's choice, so they never change. Every one also shows notices on the site; likes
 * only there.
 */
final class ForumMailCategories
{
    /** Replies in discussions the person follows: may wait for the daily digest */
    public const string REPLIES = 'forum.replies';

    /** New discussions in categories the person follows, and campaign spaces they can now enter */
    public const string DISCUSSIONS = 'forum.discussions';

    /** Being mentioned or quoted: sent at once */
    public const string MENTIONS = 'forum.mentions';

    /** Likes of the person's posts: on the site only */
    public const string LIKES = 'forum.likes';

    private const string SECTION = 'forum::messages.notices.categories.section';

    /**
     * @return list<MailCategoryDTO>
     */
    public static function all(): array
    {
        return [
            self::category(self::REPLIES, 'replies', digestible: true),
            self::category(self::DISCUSSIONS, 'discussions', digestible: true),
            self::category(self::MENTIONS, 'mentions'),
            self::category(self::LIKES, 'likes', mail: false),
        ];
    }

    private static function category(string $key, string $name, bool $digestible = false, bool $mail = true): MailCategoryDTO
    {
        return MailCategoryDTO::optional(
            key: $key,
            section: self::SECTION,
            label: "forum::messages.notices.categories.{$name}.label",
            description: "forum::messages.notices.categories.{$name}.description",
            icon: 'users',
            digestible: $digestible,
            site: true,
            mail: $mail,
        );
    }
}
