<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\Mail\Contracts\HasMailDigestLine;
use App\Application\Mail\DTOs\MailDigestLineDTO;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Application\SiteNotifications\DTOs\SiteNotificationGroupDTO;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\DTOs\PostNoticeDTO;
use Modules\Forum\Domain\Enums\FollowLevel;

/**
 * A reply in a discussion the person follows. On the site, unread replies of one discussion
 * gather in one notice. By mail, one per discussion until the person reads it again: a reply
 * after one they have not read yet sends nothing more.
 */
final class ReplyPostedNotification extends PostNotification implements HasMailDigestLine
{
    public function mailCategory(): string
    {
        return ForumMailCategories::REPLIES;
    }

    public function mailDigestLine(object $notifiable): ?MailDigestLineDTO
    {
        $notice = $this->notice($notifiable);

        return $notice instanceof PostNoticeDTO ? new MailDigestLineDTO($this->title($notice), $notice->url, $notice->post->authorId()) : null;
    }

    protected function stillHolds(PostNoticeDTO $notice, string $channel): bool
    {
        $follows = app(FollowsInterface::class);
        if ($follows->discussionLevel($notice->recipientId, $notice->discussion->id()) !== FollowLevel::Following) {
            return false;
        }

        if ($channel !== 'mail') {
            return true;
        }

        $readThrough = app(DiscussionReadsInterface::class)->readThrough($notice->recipientId, $notice->discussion->id(), $notice->discussion->categoryId());

        return $follows->claimReplyMail($notice->recipientId, $notice->discussion->id(), (int) $notice->post->number()?->value, $readThrough);
    }

    protected function title(PostNoticeDTO $notice): string
    {
        return __('forum::messages.notices.reply.title', ['author' => self::authorOf($notice), 'discussion' => $notice->discussion->title()->value]);
    }

    protected function siteNotification(PostNoticeDTO $notice): SiteNotificationDTO
    {
        $author = self::authorOf($notice);
        $title = $notice->discussion->title()->value;

        return new SiteNotificationDTO(
            title: $this->title($notice),
            url: $notice->path,
            body: $notice->excerpt === '' ? null : $notice->excerpt,
            icon: 'message-square',
            groupKey: 'forum.replies:'.$notice->discussion->id()->value,
            actorId: $notice->post->authorId(),
            groupedTitle: static fn (SiteNotificationGroupDTO $group): string => $group->otherActors === 0
                ? trans_choice('forum::messages.notices.reply.grouped_same', $group->count, ['author' => $author, 'count' => $group->count, 'discussion' => $title])
                : trans_choice('forum::messages.notices.reply.grouped', $group->otherActors, ['author' => $author, 'count' => $group->otherActors, 'discussion' => $title]),
        );
    }
}
