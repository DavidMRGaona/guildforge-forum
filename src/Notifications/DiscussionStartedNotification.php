<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\Mail\Contracts\HasMailDigestLine;
use App\Application\Mail\DTOs\MailDigestLineDTO;
use App\Application\Mail\DTOs\MailSubjectDTO;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Application\SiteNotifications\DTOs\SiteNotificationGroupDTO;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\DTOs\PostNoticeDTO;
use Modules\Forum\Application\Services\ForumMailSubjects;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;

/**
 * A new discussion in a category the person follows (or in one of its subcategories). It is
 * about the category: muting it from the mail holds back its new discussions. On the site, the
 * unread ones of a category gather in one notice.
 */
final class DiscussionStartedNotification extends PostNotification implements HasMailDigestLine
{
    public function __construct(
        string $postId,
        public readonly string $categoryId,
    ) {
        parent::__construct($postId);
    }

    public function mailCategory(): string
    {
        return ForumMailCategories::DISCUSSIONS;
    }

    public function mailSubject(): ?MailSubjectDTO
    {
        $category = app(CategoryRepositoryInterface::class)->find(CategoryId::fromString($this->categoryId));

        return $category instanceof Category ? ForumMailSubjects::category($category) : null;
    }

    public function mailDigestLine(object $notifiable): ?MailDigestLineDTO
    {
        $notice = $this->notice($notifiable);

        return $notice instanceof PostNoticeDTO ? new MailDigestLineDTO($this->title($notice), $notice->url, $notice->post->authorId()) : null;
    }

    protected function stillHolds(PostNoticeDTO $notice, string $channel): bool
    {
        $follows = app(FollowsInterface::class);
        $categoryIds = [$notice->category->id(), ...($notice->category->parentId() instanceof CategoryId ? [$notice->category->parentId()] : [])];

        return array_any($categoryIds, fn ($categoryId) => $follows->followsCategory($notice->recipientId, $categoryId));
    }

    protected function title(PostNoticeDTO $notice): string
    {
        return __('forum::messages.notices.discussion.title', [
            'author' => self::authorOf($notice),
            'discussion' => $notice->discussion->title()->value,
            'category' => $notice->category->name(),
        ]);
    }

    protected function siteNotification(PostNoticeDTO $notice): SiteNotificationDTO
    {
        $category = $notice->category->name();

        return new SiteNotificationDTO(
            title: $this->title($notice),
            url: $notice->path,
            body: $notice->excerpt === '' ? null : $notice->excerpt,
            icon: 'message-circle',
            groupKey: 'forum.discussions:'.$this->categoryId,
            actorId: $notice->post->authorId(),
            groupedTitle: static fn (SiteNotificationGroupDTO $group): string => trans_choice(
                'forum::messages.notices.discussion.grouped',
                $group->count,
                ['count' => $group->count, 'category' => $category],
            ),
        );
    }
}
