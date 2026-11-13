<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Testing\TestResponse;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Tests\Support\BuildsConversations;
use Modules\Forum\Tests\Support\BuildsForumContent;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Realtime\EnablesRealtime;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Who may listen to the forum's real-time channels: exactly who may read what each one feeds,
 * by the same access rules as the pages. Anything else, and any check that fails, refuses.
 */
final class RealtimeChannelsTest extends ModuleTestCase
{
    use BuildsConversations;
    use BuildsForumContent;
    use EnablesRealtime;
    use UsesUtcStorage;

    private const string SOCKET = '1234.5678';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $public;

    private Category $members;

    private UserModel $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->enableRealtime();
        $this->public = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios', position: 1);
        $this->reader = UserModel::factory()->create();
    }

    public function test_a_reader_of_the_discussion_listens_to_it(): void
    {
        [$discussion] = $this->aDiscussion($this->public);
        $channel = 'forum.discussion.'.$discussion->id()->value;

        $this->subscribe($this->reader, $channel)
            ->assertOk()
            ->assertJsonPath('auth', $this->expectedRealtimeAuth(self::SOCKET, 'private-'.$channel));
    }

    public function test_whoever_may_not_read_the_discussion_does_not_listen(): void
    {
        [$private] = $this->aDiscussion($this->members);

        $this->subscribe($this->reader, 'forum.discussion.'.$private->id()->value)->assertForbidden();
    }

    public function test_a_hidden_discussion_is_listened_to_by_its_moderators_only(): void
    {
        [$discussion] = $this->aDiscussion($this->public);
        app(ForumWriter::class)->changeDiscussion($discussion->id(), static fn (Discussion $hidden) => $hidden->hide());
        $channel = 'forum.discussion.'.$discussion->id()->value;

        $this->subscribe($this->reader, $channel)->assertForbidden();
        $this->subscribe($this->anAccount('forum:forum.moderate'), $channel)->assertOk();
    }

    public function test_a_discussion_awaiting_approval_is_listened_to_by_its_author_and_moderators(): void
    {
        $author = UserModel::factory()->create();
        [$discussion] = $this->aDiscussion($this->public, authorId: strtolower((string) $author->id), needsApproval: true);
        $channel = 'forum.discussion.'.$discussion->id()->value;

        $this->subscribe($this->reader, $channel)->assertForbidden();
        $this->subscribe($author, $channel)->assertOk();
        $this->subscribe($this->anAccount('forum:forum.moderate'), $channel)->assertOk();
    }

    public function test_a_read_only_category_is_still_listened_to_by_its_readers(): void
    {
        $this->public->makeReadOnly();
        app(CategoryRepositoryInterface::class)->save($this->public);
        [$discussion] = $this->aDiscussion($this->public);

        $this->subscribe($this->reader, 'forum.discussion.'.$discussion->id()->value)->assertOk();
        $this->subscribe($this->reader, 'forum.category.'.$this->public->id()->value)->assertOk();
    }

    public function test_someone_suspended_in_the_forum_listens_while_they_still_read_like_the_page(): void
    {
        [$discussion] = $this->aDiscussion($this->public);
        app(SuspensionService::class)->suspend($this->anAccount('forum:forum.moderate'), (string) $this->reader->id, 'Insultos', null);

        $this->getJson(route('forum.discussions.updates', ['discussion' => $discussion->id()->value]).'?despues=0')->assertOk();
        $this->subscribe($this->reader, 'forum.discussion.'.$discussion->id()->value)->assertOk();
    }

    public function test_an_unknown_or_malformed_discussion_refuses(): void
    {
        $this->subscribe($this->reader, 'forum.discussion.11111111-1111-4111-8111-111111111111')->assertForbidden();
        $this->subscribe($this->reader, 'forum.discussion.not-an-id')->assertForbidden();
    }

    public function test_a_check_that_throws_refuses(): void
    {
        [$discussion] = $this->aDiscussion($this->public);
        $this->app->instance(ForumAccessInterface::class, new class implements ForumAccessInterface
        {
            public function canRead(?Authenticatable $user, Discussion $discussion): bool
            {
                throw new RuntimeException('The rules could not be read');
            }

            public function canWrite(?Authenticatable $user, Discussion $discussion): bool
            {
                throw new RuntimeException('The rules could not be read');
            }

            public function canModerate(?Authenticatable $user, Discussion $discussion): bool
            {
                throw new RuntimeException('The rules could not be read');
            }
        });

        $this->subscribe($this->reader, 'forum.discussion.'.$discussion->id()->value)->assertForbidden();
    }

    public function test_a_reader_of_the_category_listens_to_it_and_nobody_else(): void
    {
        $this->subscribe($this->reader, 'forum.category.'.$this->public->id()->value)->assertOk();
        $this->subscribe($this->reader, 'forum.category.'.$this->members->id()->value)->assertForbidden();
        $this->subscribe($this->reader, 'forum.category.11111111-1111-4111-8111-111111111111')->assertForbidden();
        $this->subscribe($this->reader, 'forum.category.general')->assertForbidden();
    }

    public function test_a_category_whose_rules_cannot_be_read_refuses(): void
    {
        $this->app->instance(CategoryRepositoryInterface::class, new class
        {
            /**
             * @param  array<mixed>  $arguments
             */
            public function __call(string $name, array $arguments): never
            {
                throw new RuntimeException('The categories could not be read');
            }
        });

        $this->subscribe($this->reader, 'forum.category.'.$this->public->id()->value)->assertForbidden();
    }

    public function test_an_account_listens_to_its_own_pulse_only(): void
    {
        $other = UserModel::factory()->create();

        $this->subscribe($this->reader, 'forum.account.'.strtolower((string) $this->reader->id))->assertOk();
        $this->subscribe($this->reader, 'forum.account.'.strtoupper((string) $this->reader->id))->assertForbidden();
        $this->subscribe($this->reader, 'forum.account.'.strtolower((string) $other->id))->assertForbidden();
    }

    public function test_only_whoever_is_in_the_conversation_listens_to_it(): void
    {
        $luis = UserModel::factory()->create();
        $conversation = $this->aConversation($this->reader, $luis);
        $channel = 'forum.conversation.'.$conversation->id()->value;

        $this->subscribe($this->reader, $channel)->assertOk();
        $this->subscribe($luis, $channel)->assertOk();
        // Nobody else, whatever they may do in the forum
        $this->subscribe($this->anAccount('forum:forum.moderate'), $channel)->assertForbidden();
        $this->subscribe(UserModel::factory()->create(), $channel)->assertForbidden();
        $this->subscribe($this->reader, 'forum.conversation.11111111-1111-4111-8111-111111111111')->assertForbidden();
        $this->subscribe($this->reader, 'forum.conversation.'.strtoupper($conversation->id()->value))->assertForbidden();
    }

    public function test_leaving_the_conversation_stops_the_listening(): void
    {
        $luis = UserModel::factory()->create();
        $conversation = $this->aConversation($this->reader, $luis);
        $this->conversations()->leave($luis, $conversation->id());

        $this->subscribe($luis, 'forum.conversation.'.$conversation->id()->value)->assertForbidden();
    }

    public function test_a_guest_never_listens(): void
    {
        [$discussion] = $this->aDiscussion($this->public);

        $this->postJson(route('realtime.auth'), ['socket_id' => self::SOCKET, 'channel_name' => 'private-forum.discussion.'.$discussion->id()->value])
            ->assertUnauthorized();
    }

    /**
     * @return TestResponse<Response>
     */
    private function subscribe(Authenticatable $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->postJson(route('realtime.auth'), [
            'socket_id' => self::SOCKET,
            'channel_name' => 'private-'.$channel,
        ]);
    }
}
