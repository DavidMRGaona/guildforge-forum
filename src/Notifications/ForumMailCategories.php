<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\Mail\DTOs\MailCategoryDTO;
use Closure;
use Modules\Forum\Application\Services\ForumVisibility;

/**
 * The forum's mail categories, in the «Foro» section of the profile. Their keys are stored with
 * each person's choice, so they never change. Every one also shows notices on the site; likes
 * only there. Suspensions are the only mandatory one: whoever is suspended must be told why.
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

    /** Moderation published a post or discussion of the person that was waiting for approval */
    public const string APPROVALS = 'forum.approvals';

    /** The person was suspended in the forum: always sent, never held for a digest */
    public const string SUSPENSIONS = 'forum.suspensions';

    /** For whoever moderates: someone reported a post */
    public const string REPORTS = 'forum.reports';

    /** Private conversations: being added to one and new messages in them (never their text) */
    public const string CONVERSATIONS = 'forum.conversations';

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
            self::category(self::APPROVALS, 'approvals'),
            MailCategoryDTO::mandatory(
                key: self::SUSPENSIONS,
                section: self::SECTION,
                label: 'forum::messages.notices.categories.suspensions.label',
                description: 'forum::messages.notices.categories.suspensions.description',
                reason: 'forum::messages.notices.categories.suspensions.reason',
                audience: self::audience(),
                icon: 'users',
                site: true,
            ),
            self::category(self::REPORTS, 'reports'),
            self::category(self::CONVERSATIONS, 'conversations'),
        ];
    }

    private static function category(string $key, string $name, bool $digestible = false, bool $mail = true): MailCategoryDTO
    {
        return MailCategoryDTO::optional(
            key: $key,
            section: self::SECTION,
            label: "forum::messages.notices.categories.{$name}.label",
            description: "forum::messages.notices.categories.{$name}.description",
            audience: self::audience(),
            icon: 'users',
            digestible: $digestible,
            site: true,
            mail: $mail,
        );
    }

    /**
     * Listed in the profile only to whoever sees the forum: a preview hides it from everyone else.
     *
     * @return Closure(object): bool
     */
    private static function audience(): Closure
    {
        return static fn (object $user): bool => app(ForumVisibility::class)->isVisibleTo($user);
    }
}
