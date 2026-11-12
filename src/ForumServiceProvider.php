<?php

declare(strict_types=1);

namespace Modules\Forum;

use App\Application\Mail\DTOs\MailCategoryDTO;
use App\Application\Media\Contracts\MediaUsageSourceInterface;
use App\Application\Modules\DTOs\ModuleRouteDTO;
use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Application\Modules\DTOs\SlotRegistrationDTO;
use App\Domain\Publications\Events\PublicationChanged;
use App\Domain\Publications\Events\PublicationRemoved;
use App\Domain\Publications\Events\PublicationRosterChanged;
use App\Infrastructure\Media\Observers\RefreshMediaUsagesObserver;
use App\Modules\ModuleServiceProvider;
use Filament\Pages\Page;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Forum\Application\Content\Contracts\MarkTypeInterface;
use Modules\Forum\Application\Content\Contracts\NodeTypeInterface;
use Modules\Forum\Application\Content\DocumentSchema;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\ForumCountersInterface;
use Modules\Forum\Application\Contracts\ForumNoticesInterface;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Contracts\MentionCandidatesInterface;
use Modules\Forum\Application\Contracts\PostImageStorageInterface;
use Modules\Forum\Application\Contracts\PostLikesInterface;
use Modules\Forum\Application\Contracts\PostLinksInterface;
use Modules\Forum\Application\Contracts\PostRateLimiterInterface;
use Modules\Forum\Application\Contracts\PublicationSyncQueueInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Queries\CategoryStatsQueryInterface;
use Modules\Forum\Application\Queries\DiscussionListQueryInterface;
use Modules\Forum\Application\Queries\ModerationQueueQueryInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumSuspensions;
use Modules\Forum\Console\Commands\PurgeDeletedPostsCommand;
use Modules\Forum\Console\Commands\SyncPublicationDiscussionsCommand;
use Modules\Forum\Domain\Events\ForumPostApproved;
use Modules\Forum\Domain\Events\ForumPostReported;
use Modules\Forum\Domain\Events\ForumUserSuspended;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\ModerationLogRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRevisionRepositoryInterface;
use Modules\Forum\Domain\Repositories\ReportRepositoryInterface;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\SuspensionRepositoryInterface;
use Modules\Forum\Filament\Pages\ForumModerationPage;
use Modules\Forum\Filament\Pages\ForumSettingsPage;
use Modules\Forum\Filament\Widgets\ForumActivityWidget;
use Modules\Forum\Infrastructure\Listeners\NotifyModeratorsOfReport;
use Modules\Forum\Infrastructure\Listeners\NotifyPostAuthorOfApproval;
use Modules\Forum\Infrastructure\Listeners\NotifyUserOfSuspension;
use Modules\Forum\Infrastructure\Listeners\QueuePublicationDiscussionSync;
use Modules\Forum\Infrastructure\Listeners\QueueSpaceRosterSync;
use Modules\Forum\Infrastructure\Media\PostImageMediaSource;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentCategoryRepository;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentDiscussionRepository;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentModerationLogRepository;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentPostRepository;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentPostRevisionRepository;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentReportRepository;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentSubjectDiscussionRepository;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentSuspensionRepository;
use Modules\Forum\Infrastructure\Queries\EloquentCategoryStatsQuery;
use Modules\Forum\Infrastructure\Queries\EloquentDiscussionListQuery;
use Modules\Forum\Infrastructure\Queries\EloquentModerationQueueQuery;
use Modules\Forum\Infrastructure\Services\CategoryForumAccess;
use Modules\Forum\Infrastructure\Services\CloudinaryPostImageStorage;
use Modules\Forum\Infrastructure\Services\DiscussionPostLinks;
use Modules\Forum\Infrastructure\Services\EloquentAccountDirectory;
use Modules\Forum\Infrastructure\Services\EloquentDiscussionReads;
use Modules\Forum\Infrastructure\Services\EloquentFollows;
use Modules\Forum\Infrastructure\Services\EloquentForumCounters;
use Modules\Forum\Infrastructure\Services\EloquentMentionCandidates;
use Modules\Forum\Infrastructure\Services\EloquentPostLikes;
use Modules\Forum\Infrastructure\Services\EloquentSystemAccount;
use Modules\Forum\Infrastructure\Services\ForumSettings;
use Modules\Forum\Infrastructure\Services\JobPublicationSyncQueue;
use Modules\Forum\Infrastructure\Services\QueuedForumNotices;
use Modules\Forum\Infrastructure\Services\RouteForumUrls;
use Modules\Forum\Infrastructure\Services\SettingsPostRateLimiter;
use Modules\Forum\Notifications\ForumMailCategories;
use Modules\Forum\Policies\CategoryPolicy;

final class ForumServiceProvider extends ModuleServiceProvider
{
    /**
     * Permission names, stored by the loader as "forum:<name>".
     */
    private const array PERMISSIONS = ['forum.moderate', 'categories.manage', 'settings.manage', 'posts.upload_images'];

    /**
     * Container tags for the post document's extra node and mark types: a feature that adds a
     * node (dice rolls, asides) tags its class here and the schema takes it in.
     */
    public const string NODE_TYPES_TAG = 'forum.content.nodes';

    public const string MARK_TYPES_TAG = 'forum.content.marks';

    public const string IMAGE_UPLOADS_LIMITER = 'forum-images';

    public function moduleName(): string
    {
        return 'forum';
    }

    public function register(): void
    {
        parent::register();

        $this->app->bind(CategoryRepositoryInterface::class, EloquentCategoryRepository::class);
        $this->app->bind(DiscussionRepositoryInterface::class, EloquentDiscussionRepository::class);
        $this->app->bind(PostRepositoryInterface::class, EloquentPostRepository::class);
        $this->app->bind(PostRevisionRepositoryInterface::class, EloquentPostRevisionRepository::class);
        $this->app->bind(ForumCountersInterface::class, EloquentForumCounters::class);
        $this->app->bind(SubjectDiscussionRepositoryInterface::class, EloquentSubjectDiscussionRepository::class);
        $this->app->bind(ModerationLogRepositoryInterface::class, EloquentModerationLogRepository::class);
        $this->app->bind(ReportRepositoryInterface::class, EloquentReportRepository::class);
        $this->app->bind(SuspensionRepositoryInterface::class, EloquentSuspensionRepository::class);
        $this->app->bind(SystemAccountInterface::class, EloquentSystemAccount::class);
        $this->app->bind(PublicationSyncQueueInterface::class, JobPublicationSyncQueue::class);
        $this->app->bind(ForumSettingsInterface::class, ForumSettings::class);
        // Roles and rosters are read once per request (or queue job)
        $this->app->scoped(CategoryAccessPolicy::class);
        // Suspensions are read once per person and request (or queue job)
        $this->app->scoped(ForumSuspensions::class);
        $this->app->bind(AccountDirectoryInterface::class, EloquentAccountDirectory::class);
        $this->app->bind(MentionCandidatesInterface::class, EloquentMentionCandidates::class);
        $this->app->bind(PostImageStorageInterface::class, CloudinaryPostImageStorage::class);
        $this->app->bind(PostRateLimiterInterface::class, SettingsPostRateLimiter::class);
        $this->app->bind(PostLinksInterface::class, DiscussionPostLinks::class);
        $this->app->bind(ForumUrlsInterface::class, RouteForumUrls::class);
        $this->app->bind(DiscussionReadsInterface::class, EloquentDiscussionReads::class);
        $this->app->bind(PostLikesInterface::class, EloquentPostLikes::class);
        $this->app->bind(FollowsInterface::class, EloquentFollows::class);
        $this->app->bind(ForumNoticesInterface::class, QueuedForumNotices::class);
        $this->app->bind(DiscussionListQueryInterface::class, EloquentDiscussionListQuery::class);
        $this->app->bind(CategoryStatsQueryInterface::class, EloquentCategoryStatsQuery::class);
        $this->app->bind(ModerationQueueQueryInterface::class, EloquentModerationQueueQuery::class);
        // The category rules, per request like the policy they read
        $this->app->scoped(ForumAccessInterface::class, CategoryForumAccess::class);

        // Built each time: the images a post may hold come from the settings
        $this->app->bind(DocumentSchema::class, static function (Application $app): DocumentSchema {
            /** @var iterable<NodeTypeInterface> $nodes */
            $nodes = $app->tagged(self::NODE_TYPES_TAG);
            /** @var iterable<MarkTypeInterface> $marks */
            $marks = $app->tagged(self::MARK_TYPES_TAG);

            return new DocumentSchema(
                [...DocumentSchema::coreNodes($app->make(ForumSettingsInterface::class)->imagesPerPost()), ...$nodes],
                [...DocumentSchema::coreMarks(), ...$marks],
            );
        });
    }

    public function boot(): void
    {
        parent::boot();

        // Image uploads per person and hour, from the settings
        RateLimiter::for(self::IMAGE_UPLOADS_LIMITER, static fn (Request $request): Limit => Limit::perHour(app(ForumSettingsInterface::class)->imageUploadsPerHour())
            ->by('forum-images:'.self::requesterKey($request)));

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeDeletedPostsCommand::class, SyncPublicationDiscussionsCommand::class]);
        }

        // Game tables, campaigns and events get their discussion; each change is checked in the queue
        Event::listen(PublicationChanged::class, [QueuePublicationDiscussionSync::class, 'handle']);
        Event::listen(PublicationRemoved::class, [QueuePublicationDiscussionSync::class, 'handle']);
        // Joining a campaign follows its space (and tells so); leaving it ends that follow
        Event::listen(PublicationRosterChanged::class, [QueueSpaceRosterSync::class, 'handle']);

        // What moderation does is told to whom it concerns, after the commit
        Event::listen(ForumPostApproved::class, [NotifyPostAuthorOfApproval::class, 'handle']);
        Event::listen(ForumUserSuspended::class, [NotifyUserOfSuspension::class, 'handle']);
        Event::listen(ForumPostReported::class, [NotifyModeratorsOfReport::class, 'handle']);

        // What authors deleted is emptied once its retention has passed
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('forum:purge-deleted-posts')->dailyAt('04:10')->withoutOverlapping(60);
            // What was published before the module was enabled, or a check that got lost
            $schedule->command('forum:sync-publications')->dailyAt('04:40')->withoutOverlapping(60);
        });

        // The media library learns at once which images a post started or stopped using
        $observer = new RefreshMediaUsagesObserver(
            PostImageMediaSource::TYPE,
            static fn (Model $post): array => $post instanceof PostModel && self::touchesImages($post) ? [$post->id] : [],
        );
        PostModel::saved($observer->saved(...));
        PostModel::deleted($observer->deleted(...));
    }

    /**
     * @return array<class-string<MediaUsageSourceInterface>>
     */
    public function registerMediaSources(): array
    {
        return [PostImageMediaSource::class];
    }

    /**
     * The «Foro» section of the profile's notices: replies, new discussions, mentions and likes.
     *
     * @return list<MailCategoryDTO>
     */
    public function registerMailCategories(): array
    {
        return ForumMailCategories::all();
    }

    /**
     * - The «Foro» tab of the profile: following after posting, and what the person follows.
     *   The section loads GET /foro/preferencias itself.
     * - The conversation of a record (game table, campaign, event) in its page: the page hands
     *   `subjectType` and `subjectId` to the slot, and the component asks for the rest.
     *
     * @return array<SlotRegistrationDTO>
     */
    public function registerSlots(): array
    {
        return [
            new SlotRegistrationDTO(
                slot: 'profile-sections',
                component: 'components/profile/ForumPreferences.vue',
                module: $this->moduleName(),
                order: 40,
                props: [],
                dataKeys: [],
                profileTab: [
                    'tabId' => 'forum',
                    'icon' => 'cog',
                    'labelKey' => 'forum.preferences.tabLabel',
                ],
            ),
            new SlotRegistrationDTO(
                slot: 'subject-discussion',
                component: 'components/subjects/SubjectConversation.vue',
                module: $this->moduleName(),
                order: 10,
            ),
        ];
    }

    /**
     * Never granted to any role, nor suggested: each association gives them to the roles it
     * creates (a moderation team, whoever runs the forum). Admins hold them all.
     *
     * @return array<PermissionDTO>
     */
    public function registerPermissions(): array
    {
        return array_map(
            fn (string $name): PermissionDTO => new PermissionDTO(
                name: $name,
                label: __("forum::messages.permissions.{$name}"),
                group: __('forum::messages.title'),
                description: __("forum::messages.permissions.{$name}_description"),
                module: $this->moduleName(),
            ),
            self::PERMISSIONS,
        );
    }

    /**
     * @return array<class-string, class-string>
     */
    public function registerPolicies(): array
    {
        return [CategoryModel::class => CategoryPolicy::class];
    }

    /**
     * Module pages render as "Forum/<Page>" inside the core layout.
     *
     * @return array<PagePrefixDTO>
     */
    public function registerPagePrefixes(): array
    {
        return [
            new PagePrefixDTO(prefix: 'Forum', module: $this->moduleName(), usesCoreLayout: true),
        ];
    }

    /**
     * The forum's front page, for the menu editor (Sitio web → Navegación). The link itself is
     * added once, when the module is first enabled (ForumMenuLinkSeeder).
     *
     * @return array<ModuleRouteDTO>
     */
    public function registerRoutes(): array
    {
        return [
            new ModuleRouteDTO(
                routeName: 'forum.index',
                label: __('forum::messages.title'),
                module: $this->moduleName(),
            ),
        ];
    }

    /**
     * @return array<class-string<Page>>
     */
    public function registerFilamentPages(): array
    {
        return [ForumSettingsPage::class, ForumModerationPage::class];
    }

    /**
     * @return array<class-string>
     */
    public function registerFilamentWidgets(): array
    {
        return [ForumActivityWidget::class];
    }

    /**
     * Whether a saved or deleted post had or has images, so most posts queue no refresh.
     */
    private static function touchesImages(PostModel $post): bool
    {
        foreach ([$post->getOriginal('body'), $post->getAttribute('body')] as $body) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES);
            if (is_string($json) && str_contains($json, ImageNode::FOLDER)) {
                return true;
            }
        }

        return false;
    }

    private static function requesterKey(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : (string) $request->ip();
    }
}
