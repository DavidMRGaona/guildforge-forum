<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Application\SiteNotifications\DTOs\SiteNotificationGroupDTO;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\PostLikesInterface;
use Modules\Forum\Application\DTOs\PostNoticeDTO;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Someone liked one of the person's posts: on the site only, and the unread likes of one post
 * gather in one notice. Nothing arrives when the like was taken back before it was delivered.
 */
final class PostLikedNotification extends PostNotification
{
    public function __construct(
        string $postId,
        public readonly string $likerId,
    ) {
        parent::__construct($postId);
    }

    public function mailCategory(): string
    {
        return ForumMailCategories::LIKES;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [HasSiteNotification::CHANNEL];
    }

    protected function stillHolds(PostNoticeDTO $notice, string $channel): bool
    {
        if ($notice->post->authorId() !== $notice->recipientId) {
            return false;
        }

        $likes = app(PostLikesInterface::class)->of([PostId::fromString($this->postId)], $this->likerId, 0);

        return ($likes[$this->postId] ?? null)?->likedByViewer === true;
    }

    protected function title(PostNoticeDTO $notice): string
    {
        return __('forum::messages.notices.like.title', ['liker' => $this->likerName(), 'discussion' => $notice->discussion->title()->value]);
    }

    protected function siteNotification(PostNoticeDTO $notice): SiteNotificationDTO
    {
        $liker = $this->likerName();
        $title = $notice->discussion->title()->value;

        return new SiteNotificationDTO(
            title: $this->title($notice),
            url: $notice->path,
            body: $notice->excerpt === '' ? null : $notice->excerpt,
            icon: 'thumbs-up',
            groupKey: 'forum.likes:'.$this->postId,
            actorId: $this->likerId,
            groupedTitle: static fn (SiteNotificationGroupDTO $group): string => trans_choice(
                'forum::messages.notices.like.grouped',
                max(1, $group->otherActors),
                ['liker' => $liker, 'count' => max(1, $group->otherActors), 'discussion' => $title],
            ),
        );
    }

    private function likerName(): string
    {
        return app(AccountDirectoryInterface::class)->namesOf([$this->likerId])[$this->likerId] ?? __('forum::messages.notices.someone');
    }
}
