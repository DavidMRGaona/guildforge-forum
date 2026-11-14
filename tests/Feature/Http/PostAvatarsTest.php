<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Inertia\Testing\AssertableInertia;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Each post carries its author's profile avatar, read from the account when the page is drawn:
 * for every reader, guests included, and never for an account that is deactivated or anonymized,
 * nor for a post that speaks as a character (the character's portrait shows instead).
 */
final class PostAvatarsTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $author;

    private Category $category;

    private Discussion $discussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->author = UserModel::factory()->create();
        $this->author->forceFill(['avatar_public_id' => 'avatars/ana'])->save();
        $this->category = $this->aCategory();
        [$this->discussion] = $this->aDiscussion($this->category, authorId: $this->author->id);
    }

    public function test_a_post_carries_its_authors_avatar(): void
    {
        $this->actingAs(UserModel::factory()->create())->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.authorAvatarPublicId', 'avatars/ana')->etc());
    }

    public function test_a_guest_sees_the_avatar_in_a_public_category(): void
    {
        $this->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.authorAvatarPublicId', 'avatars/ana')->etc());
    }

    public function test_a_post_without_avatar_carries_null(): void
    {
        $this->author->forceFill(['avatar_public_id' => null])->save();

        $this->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.authorAvatarPublicId', null)->etc());
    }

    public function test_a_deactivated_author_shows_no_avatar(): void
    {
        $this->author->delete();

        $this->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.authorAvatarPublicId', null)->etc());
    }

    public function test_an_anonymized_author_shows_no_avatar(): void
    {
        $this->author->forceFill(['anonymized_at' => CarbonImmutable::now()])->save();

        $this->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.authorAvatarPublicId', null)->etc());
    }

    public function test_a_post_in_character_shows_no_account_avatar(): void
    {
        $arwen = $this->aCharacterOf($this->author->id);
        app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), $this->author->id, self::aBody('En personaje'), new DateTimeImmutable('2026-11-12 12:00:00'), false, $arwen->id());

        $this->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posts.1.character.name', 'Arwen')
                ->where('posts.1.authorAvatarPublicId', null)
                ->where('posts.0.authorAvatarPublicId', 'avatars/ana')
                ->etc());
    }

    private function discussionUrl(): string
    {
        $number = (int) $this->discussion->number();

        return "/foro/t/{$number}-".str($this->discussion->title()->value)->slug();
    }
}
