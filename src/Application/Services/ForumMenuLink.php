<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Services\SettingsServiceInterface;
use App\Domain\Navigation\Entities\MenuItem;
use App\Domain\Navigation\Enums\MenuVisibility;
use App\Domain\Navigation\Repositories\MenuItemRepositoryInterface;
use JsonException;

/**
 * The «Foro» menu link follows «Acceso al foro»: in preview, it shows only to whoever holds
 * forum:forum.preview; once open, it gets back the visibility it had (what an admin chose for it,
 * kept in the setting `forum_menu_link_before_preview`), or public when nothing was kept. Applying
 * it twice changes nothing. Only the forum's own links (route forum.index, module forum) are
 * touched: a link an admin added by hand is theirs.
 */
final readonly class ForumMenuLink
{
    public const string ROUTE = 'forum.index';

    public const string PREVIOUS_SETTING = 'forum_menu_link_before_preview';

    private const string MODULE = 'forum';

    public function __construct(
        private MenuItemRepositoryInterface $menu,
        private ForumVisibility $visibility,
        private SettingsServiceInterface $settings,
    ) {}

    /**
     * The visibility and permissions a new link gets under the current access.
     *
     * @return array{MenuVisibility, list<string>}
     */
    public function visibilityForNewLink(): array
    {
        return $this->visibility->isOpen() ? [MenuVisibility::Public, []] : [MenuVisibility::Permission, [ForumVisibility::PERMISSION]];
    }

    public function applyAccess(): void
    {
        $open = $this->visibility->isOpen();
        $previous = $this->previous();
        $kept = $previous;

        foreach ($this->menu->all() as $item) {
            if ($item->route() !== self::ROUTE || $item->module() !== self::MODULE) {
                continue;
            }

            $id = $item->id()->value;
            if (! $open) {
                if (! $this->isPreviewOnly($item)) {
                    $kept[$id] = ['visibility' => $item->visibility()->value, 'permissions' => $item->permissions()];
                    $this->menu->save($this->withVisibility($item, MenuVisibility::Permission, [ForumVisibility::PERMISSION]));
                }

                continue;
            }

            if ($this->isPreviewOnly($item)) {
                $before = $previous[$id] ?? null;
                $visibility = MenuVisibility::tryFrom($before['visibility'] ?? '') ?? MenuVisibility::Public;
                $this->menu->save($this->withVisibility($item, $visibility, $visibility === MenuVisibility::Permission ? ($before['permissions'] ?? []) : []));
            }
            unset($kept[$id]);
        }

        if ($kept !== $previous) {
            $this->settings->set(self::PREVIOUS_SETTING, json_encode($kept, JSON_THROW_ON_ERROR));
        }
    }

    private function isPreviewOnly(MenuItem $item): bool
    {
        return $item->visibility() === MenuVisibility::Permission && $item->permissions() === [ForumVisibility::PERMISSION];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function withVisibility(MenuItem $item, MenuVisibility $visibility, array $permissions): MenuItem
    {
        return new MenuItem(
            id: $item->id(),
            location: $item->location(),
            parentId: $item->parentId(),
            label: $item->label(),
            url: $item->url(),
            route: $item->route(),
            routeParams: $item->routeParams(),
            icon: $item->icon(),
            target: $item->target(),
            visibility: $visibility,
            permissions: $permissions,
            sortOrder: $item->sortOrder(),
            isActive: $item->isActive(),
            module: $item->module(),
            createdAt: $item->createdAt(),
            updatedAt: $item->updatedAt(),
        );
    }

    /**
     * What each link had before the preview, by id; anything unreadable counts as nothing kept.
     *
     * @return array<string, array{visibility: string, permissions: list<string>}>
     */
    private function previous(): array
    {
        $stored = $this->settings->get(self::PREVIOUS_SETTING);
        if (! is_string($stored) || $stored === '') {
            return [];
        }

        try {
            $decoded = json_decode($stored, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $previous = [];
        foreach (is_array($decoded) ? $decoded : [] as $id => $entry) {
            if (is_string($id) && is_array($entry) && is_string($entry['visibility'] ?? null) && is_array($entry['permissions'] ?? null)) {
                $previous[$id] = [
                    'visibility' => $entry['visibility'],
                    'permissions' => array_values(array_filter($entry['permissions'], is_string(...))),
                ];
            }
        }

        return $previous;
    }
}
