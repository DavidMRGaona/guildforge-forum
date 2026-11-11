<?php

declare(strict_types=1);

namespace Modules\Forum\Database\Seeders;

use App\Domain\Navigation\Entities\MenuItem;
use App\Domain\Navigation\Enums\LinkTarget;
use App\Domain\Navigation\Enums\MenuLocation;
use App\Domain\Navigation\Enums\MenuVisibility;
use App\Domain\Navigation\Repositories\MenuItemRepositoryInterface;
use App\Domain\Navigation\ValueObjects\MenuItemId;
use Illuminate\Database\Seeder;

/**
 * Adds «Foro» at the end of the header menu when the module is first enabled. It carries the
 * module's name, so the core hides it while the module is disabled and removes it on
 * uninstall; admins move or remove it in Sitio web → Navegación. Never added twice.
 */
final class ForumMenuLinkSeeder extends Seeder
{
    public const string ROUTE = 'forum.index';

    public function run(): void
    {
        // Resolved here, not in the constructor: seeders are built before the providers bind
        $menu = app(MenuItemRepositoryInterface::class);

        foreach ($menu->all() as $item) {
            if ($item->route() === self::ROUTE) {
                return;
            }
        }

        // Seeders may run before the module's translations are registered
        app('translator')->addNamespace('forum', dirname(__DIR__, 2).'/lang');

        $menu->save(new MenuItem(
            id: MenuItemId::generate(),
            location: MenuLocation::Header,
            parentId: null,
            label: __('forum::messages.title'),
            url: null,
            route: self::ROUTE,
            routeParams: [],
            icon: 'heroicon-o-chat-bubble-left-right',
            target: LinkTarget::Self,
            visibility: MenuVisibility::Public,
            permissions: [],
            sortOrder: $menu->maxSortOrder(MenuLocation::Header) + 10,
            isActive: true,
            module: 'forum',
        ));
    }
}
