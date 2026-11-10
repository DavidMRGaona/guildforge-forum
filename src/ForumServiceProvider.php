<?php

declare(strict_types=1);

namespace Modules\Forum;

use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Modules\ModuleServiceProvider;
use Filament\Pages\Page;
use Modules\Forum\Application\Contracts\ForumCountersInterface;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Filament\Pages\ForumSettingsPage;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentCategoryRepository;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentDiscussionRepository;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentPostRepository;
use Modules\Forum\Infrastructure\Services\EloquentForumCounters;
use Modules\Forum\Infrastructure\Services\ForumSettings;
use Modules\Forum\Policies\CategoryPolicy;

final class ForumServiceProvider extends ModuleServiceProvider
{
    /**
     * Permission names, stored by the loader as "forum:<name>".
     */
    private const array PERMISSIONS = ['forum.moderate', 'categories.manage', 'settings.manage'];

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
        $this->app->bind(ForumCountersInterface::class, EloquentForumCounters::class);
        $this->app->bind(ForumSettingsInterface::class, ForumSettings::class);
        // Roles and rosters are read once per request (or queue job)
        $this->app->scoped(CategoryAccessPolicy::class);
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
     * @return array<class-string<Page>>
     */
    public function registerFilamentPages(): array
    {
        return [ForumSettingsPage::class];
    }
}
