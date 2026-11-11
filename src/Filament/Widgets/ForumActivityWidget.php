<?php

declare(strict_types=1);

namespace Modules\Forum\Filament\Widgets;

use App\Application\Services\TimezoneServiceInterface;
use App\Filament\Concerns\ChecksPermissions;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Filament\Pages\ForumModerationPage;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ReportModel;

/**
 * The forum at a glance for whoever moderates it: discussions and posts of the last 30 days, open
 * reports and what awaits approval (linked to the moderation page). Shown only with forum:forum.moderate.
 */
final class ForumActivityWidget extends StatsOverviewWidget
{
    use ChecksPermissions;

    public const int DAYS = 30;

    public static function canView(): bool
    {
        return self::userCan(CategoryAccessPolicy::MODERATE_FORUM);
    }

    protected function getHeading(): string
    {
        return __('forum::messages.moderation.widget.heading');
    }

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        // A bound in the storage zone: query bindings skip the models' zone handling
        $since = app(TimezoneServiceInterface::class)->toStorage(CarbonImmutable::now()->subDays(self::DAYS));

        $discussions = DiscussionModel::query()->where('created_at', '>=', $since)->where('awaiting_approval', false)->count();
        $posts = PostModel::query()->where('published_at', '>=', $since)->count();
        $pending = PostModel::query()->where('status', PostStatus::Pending->value)->count();
        $reports = ReportModel::query()->whereNull('resolution')->count();

        return [
            Stat::make(__('forum::messages.moderation.widget.discussions'), (string) $discussions)
                ->description(__('forum::messages.moderation.widget.last_days'))
                ->icon('heroicon-o-chat-bubble-left-right'),
            Stat::make(__('forum::messages.moderation.widget.posts'), (string) $posts)
                ->description(__('forum::messages.moderation.widget.last_days'))
                ->icon('heroicon-o-chat-bubble-bottom-center-text'),
            Stat::make(__('forum::messages.moderation.widget.reports'), (string) $reports)
                ->icon('heroicon-o-flag')
                ->color($reports > 0 ? 'danger' : 'gray')
                ->url(ForumModerationPage::getUrl()),
            Stat::make(__('forum::messages.moderation.widget.pending'), (string) $pending)
                ->icon('heroicon-o-clock')
                ->color($pending > 0 ? 'warning' : 'gray')
                ->url(ForumModerationPage::getUrl(['pestana' => ForumModerationPage::TAB_PENDING])),
        ];
    }
}
