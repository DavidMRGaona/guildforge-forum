<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Mail\Contracts\HasMailSubject;
use App\Application\Mail\DTOs\MailSubjectDTO;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Application\Services\ForumMailSubjects;
use Modules\Forum\Application\Services\ForumVisibility;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;

/**
 * «Te han añadido a la campaña X: ya puedes entrar en su espacio», on the site only. It is
 * delivered only while the person may read the space and it takes posts.
 */
final class SpaceOpenedNotification extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $spaceId,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [HasSiteNotification::CHANNEL];
    }

    public function mailCategory(): string
    {
        return ForumMailCategories::DISCUSSIONS;
    }

    public function mailSubject(): ?MailSubjectDTO
    {
        $space = $this->space();

        return $space instanceof Category ? ForumMailSubjects::category($space) : null;
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        // Whoever may not see a forum in preview hears nothing from it
        if (! app(ForumVisibility::class)->isVisibleTo($notifiable)) {
            return false;
        }

        return $this->openSpaceFor($notifiable) instanceof Category;
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        $space = $this->openSpaceFor($notifiable);
        if (! $space instanceof Category) {
            return null;
        }

        return new SiteNotificationDTO(
            title: __('forum::messages.notices.space.title', ['campaign' => $space->name()]),
            url: app(ForumUrlsInterface::class)->category($space),
            icon: 'user-plus',
            groupKey: 'forum.space:'.$this->spaceId,
        );
    }

    private function openSpaceFor(object $notifiable): ?Category
    {
        $accountId = $notifiable instanceof Authenticatable ? ForumBrowser::accountId($notifiable) : null;
        if (! $notifiable instanceof Authenticatable || $accountId === null || app(AccountDirectoryInterface::class)->mentionable([$accountId]) === []) {
            return null;
        }

        $space = $this->space();

        return $space instanceof Category && ! $space->isReadOnly() && app(CategoryAccessPolicy::class)->canView($notifiable, $space) ? $space : null;
    }

    private function space(): ?Category
    {
        return app(CategoryRepositoryInterface::class)->find(CategoryId::fromString($this->spaceId));
    }
}
