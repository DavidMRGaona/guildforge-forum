<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * A page that was open when its discussion was merged into another keeps asking and writing to
 * the old id: it is told (410) where the discussion went, only if it may read that, and else gets
 * what a discussion that does not exist gets.
 */
final class MergedDiscussionOpenPageTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

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

    private function merge(): void
    {
        app(ForumModeration::class)->merge($this->moderator, $this->absorbed->id(), $this->into->id());
    }

    private function updatesUrl(): string
    {
        return route('forum.discussions.updates', ['discussion' => $this->absorbed->id()->value, 'despues' => 1]);
    }
}
