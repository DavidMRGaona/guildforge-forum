<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Modules\Forum\Application\Services\ApprovalRule;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsForumContent;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * A category asking for approval holds newcomers' posts until they have enough published in
 * the forum; its moderators never wait, and a count that cannot be read holds the post.
 */
final class ApprovalRuleTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
    }

    public function test_without_a_threshold_nobody_waits(): void
    {
        $this->assertFalse($this->rule()->needsApproval($this->anAccount(), $this->category));
    }

    public function test_a_newcomer_waits_until_they_have_enough_published(): void
    {
        $this->threshold(2);
        $newcomer = $this->anAccount();
        $this->assertTrue($this->rule()->needsApproval($newcomer, $this->category));

        [$discussion] = $this->aDiscussion($this->category, authorId: strtolower($newcomer->id));
        $this->assertTrue($this->rule()->needsApproval($newcomer, $this->category));

        $this->replyAs($newcomer, $discussion);
        $this->assertFalse($this->rule()->needsApproval($newcomer, $this->category));
    }

    public function test_posts_moderation_hid_or_still_pending_do_not_count(): void
    {
        $this->threshold(1);
        $newcomer = $this->anAccount();
        [$discussion, $first] = $this->aDiscussion($this->category, authorId: strtolower($newcomer->id));
        app(ForumModeration::class)->hidePost($this->anAccount('forum:forum.moderate'), $first->id());
        $this->aDiscussion($this->category, authorId: strtolower($newcomer->id), needsApproval: true);

        $this->assertTrue($this->rule()->needsApproval($newcomer, $this->category));
        $this->assertTrue($discussion->id()->equals($first->discussionId()));
    }

    public function test_the_categorys_moderators_never_wait(): void
    {
        $this->threshold(5);
        $leader = $this->anAccount();
        $role = RoleModel::query()->create(['name' => 'leaders-'.strtolower(uniqid()), 'display_name' => 'Leaders']);
        $leader->roles()->attach($role->id);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($leader);
        $this->category->changeAccess(CategoryAudience::everyone(), CategoryAudience::signedIn(), new CategoryModerators([strtolower((string) $role->id)]));
        app(CategoryRepositoryInterface::class)->save($this->category);

        $this->assertFalse($this->rule()->needsApproval($leader, $this->category));
        $this->assertFalse($this->rule()->needsApproval($this->anAccount('forum:forum.moderate'), $this->category));
    }

    public function test_a_count_that_cannot_be_read_holds_the_post(): void
    {
        $this->threshold(1);
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('countPublishedBy')->willThrowException(new RuntimeException('Database down'));
        $this->app->instance(PostRepositoryInterface::class, $posts);

        $this->assertTrue($this->rule()->needsApproval($this->anAccount(), $this->category));
    }

    private function threshold(int $posts): void
    {
        $this->category->requireApprovalFor($posts);
        app(CategoryRepositoryInterface::class)->save($this->category);
    }

    private function replyAs(UserModel $author, Discussion $discussion): void
    {
        app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), strtolower($author->id), self::aBody('Hola'), new DateTimeImmutable('2026-11-10 19:00:00'), needsApproval: false);
    }

    private function rule(): ApprovalRule
    {
        return app(ApprovalRule::class);
    }
}
