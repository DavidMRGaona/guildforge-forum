<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature;

use App\Application\Modules\DTOs\PermissionDTO;
use Modules\Forum\ForumServiceProvider;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The forum's permissions are never granted on their own: each association gives them to the
 * roles it creates in the role editor.
 */
final class PermissionsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_it_registers_moderation_categories_and_settings(): void
    {
        $names = array_map(static fn (PermissionDTO $permission): string => $permission->name, $this->permissions());

        $this->assertSame(['forum.moderate', 'categories.manage', 'settings.manage', 'posts.upload_images'], $names);
    }

    public function test_every_permission_is_labelled_and_described_in_spanish(): void
    {
        foreach ($this->permissions() as $permission) {
            $this->assertSame('forum', $permission->module);
            $this->assertSame('Foro', $permission->group);
            $this->assertStringNotContainsString('forum::', $permission->label);
            $this->assertNotNull($permission->description);
            $this->assertStringNotContainsString('forum::', $permission->description);
            $this->assertFalse($permission->adminOnly);
        }
    }

    public function test_no_permission_is_granted_to_any_role(): void
    {
        foreach ($this->permissions() as $permission) {
            $this->assertSame([], $permission->roles, $permission->name);
        }
    }

    /**
     * @return list<PermissionDTO>
     */
    private function permissions(): array
    {
        return array_values((new ForumServiceProvider($this->app))->registerPermissions());
    }
}
