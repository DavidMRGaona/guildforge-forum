<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\CharacterRules;
use Modules\Forum\Application\Services\PostLikeService;
use Modules\Forum\Application\Services\PostNoticeContext;
use Modules\Forum\Application\Services\ReportService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\CharacterAccessMode;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * A deactivated account (core#461, soft deleted) does not write, like, report, moderate, use
 * characters or receive notices, while what it wrote stays visible; restoring it gives all of it
 * back except what moderation took away. The forum keeps nothing of its own about the state:
 * it asks the account every time, so there is nothing to put back.
 */
final class DeactivatedAccountTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $category;

    private Discussion $discussion;

    private Post $othersPost;

    private UserModel $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->other = UserModel::factory()->create(['name' => 'Luis', 'display_name' => null]);
        $this->category = $this->aCategory();
        [$this->discussion, $this->othersPost] = $this->aDiscussion($this->category, authorId: $this->other->id);
    }

    public function test_a_deactivated_account_writes_nowhere_and_comes_back_on_restore(): void
    {
        $user = UserModel::factory()->create();
        $this->assertTrue($this->policy()->canWrite($user, $this->category));

        $user->delete();
        $this->assertFalse($this->policy()->canWrite($user, $this->category));

        $user->restore();
        $this->assertTrue($this->policy()->canWrite($user, $this->category));
    }

    public function test_a_deactivated_moderator_moderates_nothing_until_restored(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        $this->assertTrue($this->policy()->canModerate($moderator, $this->category));
        $this->assertTrue($this->policy()->canModerateForum($moderator));

        $moderator->delete();
        $this->assertFalse($this->policy()->canModerate($moderator, $this->category));
        $this->assertFalse($this->policy()->canModerateForum($moderator));
        $this->assertFalse($this->policy()->canWrite($moderator, $this->category));

        $moderator->restore();
        $this->assertTrue($this->policy()->canModerate($moderator, $this->category));
    }

    public function test_a_deactivated_account_neither_likes_nor_reports(): void
    {
        $user = UserModel::factory()->create();
        $this->assertTrue($this->likes()->canLike($user, $this->othersPost, $this->discussion));
        $this->assertTrue($this->reports()->canReport($user, $this->othersPost, $this->discussion));

        $user->delete();
        $this->assertFalse($this->likes()->canLike($user, $this->othersPost, $this->discussion));
        $this->assertFalse($this->reports()->canReport($user, $this->othersPost, $this->discussion));

        $user->restore();
        $this->assertTrue($this->likes()->canLike($user, $this->othersPost, $this->discussion));
        $this->assertTrue($this->reports()->canReport($user, $this->othersPost, $this->discussion));
    }

    public function test_a_deactivated_account_has_no_characters_and_gets_back_all_but_those_moderation_retired(): void
    {
        $this->setCharacterAccess(CharacterAccessMode::Everyone);
        $user = UserModel::factory()->create();
        $free = $this->aCharacterOf($user->id, 'Arwen');
        $moderated = $this->aCharacterOf($user->id, 'Boromir', lockedByModeration: true);
        $this->assertTrue($this->characterRules()->mayHaveCharacters($user));
        $this->assertTrue($this->characterRules()->usableIn($user, $free, $this->category));

        $user->delete();
        $this->assertFalse($this->characterRules()->mayHaveCharacters($user));
        $this->assertFalse($this->characterRules()->usableIn($user, $free, $this->category));

        $user->restore();
        $this->assertTrue($this->characterRules()->usableIn($user, $free, $this->category));
        $this->assertFalse($this->characterRules()->usableIn($user, $moderated, $this->category));
        $stored = app(CharacterRepositoryInterface::class)->findOrFail($moderated->id());
        $this->assertTrue($stored->isRetired() && $stored->isRetirementLocked());
    }

    public function test_what_a_deactivated_account_wrote_stays_visible_under_its_name(): void
    {
        $this->other->delete();

        $post = app(PostRepositoryInterface::class)->find($this->othersPost->id());
        $this->assertInstanceOf(Post::class, $post);
        $this->assertTrue($post->isVisible());
        $this->assertSame([strtolower($this->other->id) => 'Luis'], app(AccountDirectoryInterface::class)->namesOf([$this->other->id]));
    }

    public function test_a_deactivated_account_gets_no_notices_and_gets_them_again_once_restored(): void
    {
        $reader = UserModel::factory()->create();
        $reply = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $this->assertNotNull(app(PostNoticeContext::class)->for($reader, $reply->id()));

        $reader->delete();
        $this->assertNull(app(PostNoticeContext::class)->for($reader, $reply->id()));

        $reader->restore();
        $this->assertNotNull(app(PostNoticeContext::class)->for($reader, $reply->id()));
    }

    public function test_the_reading_state_and_follows_survive_a_deactivation(): void
    {
        $user = UserModel::factory()->create();
        DB::table('forum_discussion_follows')->insert(['user_id' => $user->id, 'discussion_id' => $this->discussion->id()->value, 'level' => 'following', 'updated_at' => CarbonImmutable::now()]);

        $user->delete();
        $user->restore();

        $this->assertSame(1, DB::table('forum_discussion_follows')->where('user_id', $user->id)->count());
    }

    private function policy(): CategoryAccessPolicy
    {
        // Scoped: a new request reads everything again
        $this->app->forgetScopedInstances();

        return app(CategoryAccessPolicy::class);
    }

    private function likes(): PostLikeService
    {
        $this->app->forgetScopedInstances();

        return app(PostLikeService::class);
    }

    private function reports(): ReportService
    {
        $this->app->forgetScopedInstances();

        return app(ReportService::class);
    }

    private function characterRules(): CharacterRules
    {
        $this->app->forgetScopedInstances();

        return app(CharacterRules::class);
    }

    private function setCharacterAccess(CharacterAccessMode $mode): void
    {
        config(['modules.settings.forum.characters_access' => $mode->value]);
    }
}
