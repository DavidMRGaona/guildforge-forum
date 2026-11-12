<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Testing\TestResponse;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Tests\Support\BuildsForumContent;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * A page that was open when its discussion was merged into another keeps asking and writing to
 * the old id (news, replies, read marks, mentions, follows and the moderation menu): it is told
 * (410) where the discussion went, only if it may read that, and else gets what a discussion that
 * does not exist gets.
 */
final class MergedDiscussionOpenPageTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string UNKNOWN = '11111111-1111-4111-8111-111111111111';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private UserModel $moderator;

    private Discussion $absorbed;

    private Discussion $into;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->moderator = $this->anAccount('forum:forum.moderate');
        [$this->absorbed] = $this->aDiscussion($this->general, '2026-11-10 18:00:00', title: 'Mesa del viernes');
        $this->aReply($this->absorbed, '2026-11-10 18:10:00');
        [$this->into] = $this->aDiscussion($this->general, '2026-11-11 10:00:00', title: 'Destino');
    }

    public function test_the_news_of_a_merged_discussion_say_where_it_went(): void
    {
        $this->merge();

        $response = $this->getJson($this->updatesUrl());

        $response->assertStatus(410)
            ->assertJsonPath('mergedInto.title', 'Destino')
            ->assertJsonPath('mergedInto.url', '/foro/t/2-destino');
        // No shared cache keeps an answer that is about one person's rights
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_news_follow_a_discussion_merged_twice(): void
    {
        $this->merge();
        [$last] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Final');
        app(ForumModeration::class)->merge($this->moderator, $this->into->id(), $last->id());

        $this->getJson($this->updatesUrl())
            ->assertStatus(410)
            ->assertJsonPath('mergedInto.url', '/foro/t/3-final');
    }

    public function test_whoever_cannot_read_the_target_gets_what_a_missing_discussion_gets(): void
    {
        $this->merge();
        $category = app(CategoryRepositoryInterface::class)->findOrFail($this->into->categoryId());
        $category->changeAccess(CategoryAudience::signedIn(), CategoryAudience::signedIn(), CategoryModerators::none());
        app(CategoryRepositoryInterface::class)->save($category);
        // The access rules are read once per request: this test makes several
        app()->forgetScopedInstances();

        $missing = $this->getJson('/foro/temas/'.'11111111-1111-4111-8111-111111111111'.'/novedades?despues=1');
        $guest = $this->getJson($this->updatesUrl());

        $guest->assertNotFound()->assertJsonMissingPath('mergedInto');
        $this->assertSame($missing->getContent(), $guest->getContent());
        $this->actingAs($this->anAccount())->getJson($this->updatesUrl())->assertStatus(410)->assertJsonPath('mergedInto.title', 'Destino');
    }

    public function test_a_discussion_that_is_only_unreadable_is_not_told_apart(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios', position: 1);
        [$hidden] = $this->aDiscussion($members, '2026-11-10 18:00:00', title: 'Reservado');

        $this->getJson('/foro/temas/'.$hidden->id()->value.'/novedades?despues=1')->assertNotFound()->assertJsonMissingPath('mergedInto');
    }

    public function test_replying_from_an_old_page_says_where_the_discussion_went(): void
    {
        $this->merge();
        $reader = $this->anAccount();

        $this->actingAs($reader)
            ->postJson(route('forum.posts.store', ['discussion' => $this->absorbed->id()->value]), ['document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hola']]]]]])
            ->assertStatus(410)
            ->assertJsonPath('mergedInto.url', '/foro/t/2-destino')
            ->assertJsonPath('mergedInto.title', 'Destino');
    }

    public function test_replying_to_an_unknown_discussion_stays_not_found(): void
    {
        $this->actingAs($this->anAccount())
            ->postJson(route('forum.posts.store', ['discussion' => '11111111-1111-4111-8111-111111111111']), ['document' => []])
            ->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readerActions(): array
    {
        return ['read mark' => ['read'], 'mention candidates' => ['mentions'], 'follow' => ['follow']];
    }

    #[DataProvider('readerActions')]
    public function test_what_an_old_page_does_for_its_reader_says_where_the_discussion_went(string $action): void
    {
        $this->merge();
        $this->actingAs($this->anAccount());

        $this->readerAction($action, $this->absorbed->id()->value)
            ->assertStatus(410)
            ->assertJsonPath('mergedInto.title', 'Destino')
            ->assertJsonPath('mergedInto.url', '/foro/t/2-destino')
            ->assertJsonPath('message', 'Este tema se ha unido a «Destino».');
    }

    #[DataProvider('readerActions')]
    public function test_what_an_old_page_does_is_not_told_where_an_unreadable_discussion_went(string $action): void
    {
        $this->merge();
        $this->hideTheTarget();
        $this->actingAs($this->anAccount());
        // As the site answers: without the trace, which names lines
        config(['app.debug' => false]);

        $missing = $this->readerAction($action, self::UNKNOWN);
        $merged = $this->readerAction($action, $this->absorbed->id()->value);

        $merged->assertNotFound()->assertJsonMissingPath('mergedInto');
        // The same answer, but for the id it names
        $this->assertSame((string) $missing->getContent(), str_replace($this->absorbed->id()->value, self::UNKNOWN, (string) $merged->getContent()));
    }

    #[DataProvider('readerActions')]
    public function test_what_a_page_does_on_an_unknown_discussion_stays_not_found(string $action): void
    {
        $this->actingAs($this->anAccount());

        $this->readerAction($action, self::UNKNOWN)->assertNotFound()->assertJsonMissingPath('mergedInto');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function moderationActions(): array
    {
        return [
            'pin' => ['pin'],
            'delete' => ['delete'],
            'split' => ['split'],
            'merge' => ['merge'],
            'merge target' => ['merge-target'],
        ];
    }

    #[DataProvider('moderationActions')]
    public function test_the_moderation_menu_of_an_old_page_says_where_the_discussion_went(string $action): void
    {
        $this->merge();
        [$other] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Otro');
        $this->actingAs($this->moderator);

        $this->moderationAction($action, $this->absorbed->id()->value, $other)
            ->assertStatus(410)
            ->assertJsonPath('mergedInto.title', 'Destino')
            ->assertJsonPath('mergedInto.url', '/foro/t/2-destino');
        // Nothing happened to where it went
        $this->getJson(route('forum.discussions.updates', ['discussion' => $this->into->id()->value, 'despues' => 0]))->assertOk();
    }

    public function test_merging_into_a_discussion_that_was_merged_is_not_mistaken_for_this_one(): void
    {
        $this->merge();
        [$other] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Otro');

        // The discussion of the page still exists: only the target it names is gone
        $this->actingAs($this->moderator)
            ->postJson(route('forum.moderation.discussions.merge', ['discussion' => $other->id()->value]), ['target' => $this->absorbed->id()->value, 'confirmed' => true])
            ->assertNotFound()
            ->assertJsonMissingPath('mergedInto');
    }

    /**
     * @return TestResponse<Response>
     */
    private function readerAction(string $action, string $discussion): TestResponse
    {
        return match ($action) {
            'read' => $this->postJson(route('forum.discussions.read', ['discussion' => $discussion]), ['number' => 2]),
            'mentions' => $this->getJson(route('forum.mentions.index', ['discussion' => $discussion, 'q' => 'a'])),
            'follow' => $this->putJson(route('forum.discussions.follow', ['discussion' => $discussion]), ['level' => 'following']),
            default => self::fail("Unknown action {$action}"),
        };
    }

    /**
     * @return TestResponse<Response>
     */
    private function moderationAction(string $action, string $discussion, Discussion $other): TestResponse
    {
        return match ($action) {
            'pin' => $this->postJson(route('forum.moderation.discussions', ['discussion' => $discussion]), ['action' => 'pin']),
            'delete' => $this->deleteJson(route('forum.moderation.discussions.destroy', ['discussion' => $discussion]), ['confirmed' => true]),
            'split' => $this->postJson(route('forum.moderation.discussions.split', ['discussion' => $discussion]), [
                'numbers' => [2], 'title' => 'Aparte', 'category' => $this->general->id()->value, 'confirmed' => true,
            ]),
            'merge' => $this->postJson(route('forum.moderation.discussions.merge', ['discussion' => $discussion]), ['target' => $other->id()->value, 'confirmed' => true]),
            'merge-target' => $this->getJson(route('forum.moderation.discussions.merge-target', ['discussion' => $discussion, 'tema' => (string) $other->number()])),
            default => self::fail("Unknown action {$action}"),
        };
    }

    /**
     * Only the association's members may read where the discussion went from now on.
     */
    private function hideTheTarget(): void
    {
        $category = app(CategoryRepositoryInterface::class)->findOrFail($this->into->categoryId());
        $category->changeAccess(CategoryAudience::associationMembers(), CategoryAudience::associationMembers(), CategoryModerators::none());
        app(CategoryRepositoryInterface::class)->save($category);
        // The access rules are read once per request: this test makes several
        app()->forgetScopedInstances();
    }

    private function merge(): void
    {
        app(ForumModeration::class)->merge($this->moderator, $this->absorbed->id(), $this->into->id());
    }

    private function updatesUrl(): string
    {
        return route('forum.discussions.updates', ['discussion' => $this->absorbed->id()->value, 'despues' => 1]);
    }
}
