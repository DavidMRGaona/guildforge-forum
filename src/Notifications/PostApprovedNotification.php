<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use Modules\Forum\Application\DTOs\PostNoticeDTO;

/**
 * «Tu mensaje se ha publicado» / «Tu tema se ha publicado»: moderation approved a post that was
 * waiting. Only its author gets it, and only while they may read it. Followers hear about the
 * post through ForumWriter::approvePost() (their notice never reaches the author), so nobody
 * hears it twice.
 */
final class PostApprovedNotification extends PostNotification
{
    public function __construct(
        string $postId,
        public readonly bool $openedDiscussion,
    ) {
        parent::__construct($postId);
    }

    public function mailCategory(): string
    {
        return ForumMailCategories::APPROVALS;
    }

    protected function stillHolds(PostNoticeDTO $notice, string $channel): bool
    {
        return $notice->post->authorId() === $notice->recipientId;
    }

    protected function title(PostNoticeDTO $notice): string
    {
        return __(
            $this->openedDiscussion ? 'forum::messages.notices.approved.discussion' : 'forum::messages.notices.approved.post',
            ['discussion' => $notice->discussion->title()->value],
        );
    }

    protected function siteNotification(PostNoticeDTO $notice): SiteNotificationDTO
    {
        // No actor: the author is the recipient, and a notice from oneself would never be stored
        return new SiteNotificationDTO(
            title: $this->title($notice),
            url: $notice->path,
            body: $notice->excerpt === '' ? null : $notice->excerpt,
            icon: 'circle-check',
        );
    }
}
