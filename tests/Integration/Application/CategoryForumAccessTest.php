<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Infrastructure\Services\CategoryForumAccess;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Discussions follow their category's access rules; a hidden one only its moderators see.
 */
final class CategoryForumAccessTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
    }

    public function test_the_forum_binds_the_category_rules(): void
    {
        $this->assertInstanceOf(CategoryForumAccess::class, app(ForumAccessInterface::class));
    }

    public function test_a_public_discussion_is_read_by_guests_and_written_in_by_accounts(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $user = UserModel::factory()->create();

        $this->assertTrue($this->access()->canRead(null, $discussion));
        $this->assertFalse($this->access()->canWrite(null, $discussion));
        $this->assertTrue($this->access()->canWrite($user, $discussion));
        $this->assertFalse($this->access()->canModerate($user, $discussion));
        $this->assertTrue($this->access()->canWriteSomewhere($user));
        $this->assertFalse($this->access()->canWriteSomewhere(null));
    }

    public function test_a_hidden_discussion_is_only_for_its_moderators(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $discussion->hide();
        app(ForumWriter::class)->saveDiscussion($discussion);
        $user = UserModel::factory()->create();
        $moderator = $this->forumModerator();

        $this->assertFalse($this->access()->canRead(null, $discussion));
        $this->assertFalse($this->access()->canRead($user, $discussion));
        $this->assertFalse($this->access()->canWrite($user, $discussion));
        $this->assertTrue($this->access()->canRead($moderator, $discussion));
        $this->assertTrue($this->access()->canWrite($moderator, $discussion));
        $this->assertTrue($this->access()->canModerate($moderator, $discussion));
    }

    public function test_nobody_writes_anywhere_when_every_category_is_read_only_for_them(): void
    {
        $category = new Category(CategoryId::generate(), 'Anuncios', new CategorySlug('anuncios'), writeAudience: CategoryAudience::associationMembers());
        app(CategoryRepositoryInterface::class)->save($category);
        [$discussion] = $this->aDiscussion($category);
        $user = UserModel::factory()->create();

        $this->assertTrue($this->access()->canRead($user, $discussion));
        $this->assertFalse($this->access()->canWrite($user, $discussion));
        $this->assertFalse($this->access()->canWriteSomewhere($user));
    }

    private function access(): ForumAccessInterface
    {
        // As a new request: the rules remember nothing from earlier answers
        $this->app->forgetScopedInstances();

        return app(ForumAccessInterface::class);
    }

    private function forumModerator(): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'forum-team', 'display_name' => 'Forum team']);
        $permission = PermissionModel::query()->firstOrCreate(
            ['key' => CategoryAccessPolicy::MODERATE_FORUM],
            ['label' => CategoryAccessPolicy::MODERATE_FORUM, 'resource' => 'forum', 'action' => 'moderate', 'module' => 'forum'],
        );
        $role->permissions()->attach($permission->id);
        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($user);

        return $user;
    }
}
