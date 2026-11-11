<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Inertia\Testing\AssertableInertia;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ReportModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\SuspensionModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Reporting from the conversation, settling from the forum's moderation page, and suspending
 * from a post's menu.
 */
final class ReportEndpointsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MODERATE = 'forum:forum.moderate';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private UserModel $author;

    private UserModel $moderator;

    private Discussion $discussion;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->author = $this->anAccount();
        $this->moderator = $this->anAccount(self::MODERATE);
        [$this->discussion, $this->post] = $this->aDiscussion($this->general, authorId: strtolower($this->author->id));
    }

    public function test_readers_get_a_report_link_for_posts_that_are_not_theirs(): void
    {
        $url = '/foro/t/1-sesion-del-viernes';

        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.reportUrl', null));
        $this->actingAs($this->author)->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.reportUrl', null));
        $this->actingAs($this->anAccount())->get($url)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.reportUrl', route('forum.reports.store', ['post' => $this->post->id()->value])));
    }

    public function test_reporting_with_a_reason_from_the_list(): void
    {
        $reader = $this->anAccount();
        $this->postJson($this->reportUrl(), ['reason' => 'spam'])->assertUnauthorized();

        $this->actingAs($reader)->postJson($this->reportUrl(), ['reason' => 'spam', 'note' => 'Enlaces a una tienda'])->assertCreated();
        $this->actingAs($reader)->postJson($this->reportUrl(), ['reason' => 'invented'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->author)->postJson($this->reportUrl(), ['reason' => 'spam'])->assertUnprocessable()->assertJsonPath('reason', 'not_reportable');

        $report = ReportModel::query()->sole();
        $this->assertSame(['spam', 'Enlaces a una tienda'], [$report->reason, $report->note]);
    }

    public function test_reporting_has_a_limit_per_person(): void
    {
        $middleware = app('router')->getRoutes()->getByName('forum.reports.store')?->gatherMiddleware() ?? [];

        $this->assertContains('throttle:10,1,forum-reports', $middleware);
    }

    public function test_the_forums_moderation_page_lists_reports_and_settles_them(): void
    {
        $this->actingAs($this->anAccount())->postJson($this->reportUrl(), ['reason' => 'offensive'])->assertCreated();

        $this->actingAs($this->moderator)->get('/foro/moderacion')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Forum/Moderation', false)
                ->where('reported.0.postId', $this->post->id()->value)
                ->where('reported.0.reasons', ['Ofensivo o irrespetuoso'])
                ->where('reported.0.reports', 1));

        $this->actingAs($this->moderator)
            ->postJson(route('forum.reports.resolve', ['post' => $this->post->id()->value]), ['resolution' => 'hidden', 'reason' => 'Insultos'])
            ->assertOk()
            ->assertJsonPath('settled', 1);

        $this->assertSame(PostStatus::Hidden, app(PostRepositoryInterface::class)->findOrFail($this->post->id())->status());
    }

    public function test_whoever_moderates_nothing_has_no_moderation_page(): void
    {
        $this->get('/foro/moderacion')->assertRedirect(route('login'));
        $this->actingAs($this->anAccount())->get('/foro/moderacion')->assertNotFound();

        $this->actingAs($this->anAccount())->get('/foro')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('moderationUrl', null));
        $this->actingAs($this->moderator)->get('/foro')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('moderationUrl', route('forum.moderation.index')));
    }

    public function test_suspending_from_the_forum_and_what_the_person_sees(): void
    {
        $member = $this->anAccount();

        $this->actingAs($this->moderator)->get('/foro/t/1-sesion-del-viernes')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('moderation.suspendUrl', route('forum.suspensions.store')));

        $this->actingAs($this->moderator)
            ->postJson(route('forum.suspensions.store'), ['user' => $member->id, 'reason' => 'Insultos', 'days' => 3])
            ->assertCreated();
        $this->actingAs($this->moderator)
            ->postJson(route('forum.suspensions.store'), ['user' => $this->moderator->id, 'reason' => 'Prueba'])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'not_suspendable');
        $this->actingAs($this->author)
            ->postJson(route('forum.suspensions.store'), ['user' => $member->id, 'reason' => 'Prueba'])
            ->assertForbidden();

        $this->actingAs($member)->get('/foro/t/1-sesion-del-viernes')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reply.canPost', false)
                ->where('reply.suspension.reason', 'Insultos')
                ->where('reply.suspension.untilLabel', fn (?string $label): bool => $label !== null)
                ->where('posts.0.reportUrl', null));
        $this->actingAs($member)
            ->postJson(route('forum.posts.store', ['discussion' => $this->discussion->id()->value]), ['document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hola']]]]]])
            ->assertForbidden();
        $this->actingAs($member)->postJson($this->reportUrl(), ['reason' => 'spam'])->assertUnprocessable()->assertJsonPath('reason', 'suspended');
    }

    public function test_lifting_from_the_forum(): void
    {
        $member = $this->anAccount();
        $suspension = app(SuspensionService::class)->suspend($this->moderator, $member->id, 'Insultos', null);

        $this->actingAs($this->moderator)
            ->deleteJson(route('forum.suspensions.destroy', ['suspension' => $suspension->id()->value]), ['reason' => 'Ya está'])
            ->assertOk();
        $this->actingAs($this->moderator)
            ->deleteJson(route('forum.suspensions.destroy', ['suspension' => $suspension->id()->value]))
            ->assertStatus(409);

        $this->assertNotNull(SuspensionModel::query()->sole()->lifted_at);
    }

    private function reportUrl(): string
    {
        return route('forum.reports.store', ['post' => $this->post->id()->value]);
    }
}
