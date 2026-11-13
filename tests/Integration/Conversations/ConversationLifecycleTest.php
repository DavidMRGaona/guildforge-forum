<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Conversations;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Search\Services\SearchSourceRegistryInterface;
use App\Application\Services\UserServiceInterface;
use App\Application\SiteNotifications\Services\SiteNotificationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Conversations\BlockService;
use Modules\Forum\Application\Conversations\ConversationAccess;
use Modules\Forum\Application\Conversations\ConversationReportService;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\ConversationReport;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Exceptions\ConversationNotFoundException;
use Modules\Forum\Domain\Exceptions\ConversationRefusedException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\ConversationReportRepositoryInterface;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Http\Presenters\ConversationPresenter;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ConversationMessageModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ConversationModel;
use Modules\Forum\Notifications\ConversationMessageNotification;
use Modules\Forum\Tests\Support\BuildsConversations;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What happens around private conversations: anonymized accounts, reports and their copy,
 * notices that never carry the text, and the search that never sees them.
 */
final class ConversationLifecycleTest extends ModuleTestCase
{
    use BuildsConversations;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MODERATE = 'forum:forum.moderate';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $ana;

    private UserModel $luis;

    private UserModel $eva;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['scout.driver' => 'null']);
        CarbonImmutable::setTestNow('2026-11-20 10:00:00');
        $this->ana = $this->anAccount();
        $this->luis = $this->anAccount();
        $this->eva = $this->anAccount();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_an_anonymized_account_leaves_every_conversation_and_its_messages_stay_with_the_anonymous_name(): void
    {
        $withThree = $this->aConversation($this->ana, $this->luis, $this->eva);
        $onlyTwo = $this->aConversation($this->ana, $this->luis);
        $this->conversations()->reply($this->luis, $withThree->id(), $this->messageDocument('Luis escribe'));
        app(BlockService::class)->block($this->ana, $this->eva->id);
        app(BlockService::class)->block($this->eva, $this->ana->id);

        app(UserServiceInterface::class)->anonymize($this->ana->id);

        $this->assertFalse(app(ConversationAccess::class)->canRead($this->ana, $withThree->id()));
        $this->assertSame(0, DB::table('forum_conversation_participants')->where('user_id', strtolower($this->ana->id))->count());
        // Her message stays, under her anonymous name, and the others read that she left
        $entries = app(ConversationRepositoryInterface::class)->entries($withThree->id(), 1, 10);
        $this->assertSame(strtolower($this->ana->id), $entries[0]->authorId());
        $this->assertSame('Hola, ¿quedamos el viernes?', $entries[0]->body()->plainText);
        $this->assertSame('Anónimo', app(AccountDirectoryInterface::class)->namesOf([$this->ana->id])[strtolower($this->ana->id)]);
        $last = $entries[count($entries) - 1] ?? null;
        $this->assertSame('left', $last?->kind()->value);
        // Luis still has the other one, now alone
        $this->assertTrue(app(ConversationAccess::class)->canRead($this->luis, $onlyTwo->id()));
        // Her blocks, made and received, and her starts go
        $this->assertSame(0, DB::table('forum_user_blocks')->count());
        $this->assertSame(0, DB::table('forum_conversation_starts')->where('user_id', strtolower($this->ana->id))->count());

        // Doing it again changes nothing
        app(UserServiceInterface::class)->anonymize($this->ana->id);
        $this->assertCount(count($entries), app(ConversationRepositoryInterface::class)->entries($withThree->id(), 1, 20));
    }

    public function test_the_conversation_goes_when_an_anonymized_account_was_the_last_one_in_it(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        $this->conversations()->leave($this->luis, $conversation->id());

        app(UserServiceInterface::class)->anonymize($this->ana->id);

        $this->assertNull(ConversationModel::query()->find($conversation->id()->value));
    }

    public function test_reporting_copies_the_message_and_three_messages_around_it_and_nothing_else(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        foreach (range(2, 9) as $n) {
            $author = $n % 2 === 0 ? $this->luis : $this->ana;
            $this->conversations()->reply($author, $conversation->id(), $this->messageDocument("Mensaje {$n}"));
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(1));
        }
        $reported = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 6, 6)[0];

        $report = app(ConversationReportService::class)->report($this->ana, $reported->id(), ReportReason::Offensive, 'Me insulta');

        $context = $report->context();
        $this->assertSame([3, 4, 5, 6, 7, 8, 9], array_column($context, 'number'));
        $this->assertSame([false, false, false, true, false, false, false], array_column($context, 'reported'));
        $this->assertSame('Mensaje 6', $context[3]['text']);
        $this->assertSame(strtolower($this->luis->id), $report->messageAuthorId());

        // Reporting again while open changes nothing
        $this->assertSame($report->id()->value, app(ConversationReportService::class)->report($this->ana, $reported->id(), ReportReason::Spam, null)->id()->value);
        // The copy outlives what it copied
        $this->conversations()->deleteOwn($this->luis, $reported->id());
        $this->conversations()->leave($this->ana, $conversation->id());
        $this->conversations()->leave($this->luis, $conversation->id());
        $stored = app(ConversationReportRepositoryInterface::class)->find($report->id());
        $this->assertInstanceOf(ConversationReport::class, $stored);
        $this->assertNull($stored->messageId());
        $this->assertSame('Mensaje 6', $stored->context()[3]['text']);
    }

    public function test_the_copy_of_a_report_stays_when_the_whole_conversation_goes(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis, $this->eva);
        $first = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 1, 1)[0];
        $report = app(ConversationReportService::class)->report($this->luis, $first->id(), ReportReason::Offensive, null);

        foreach ([$this->ana, $this->luis, $this->eva] as $user) {
            $this->conversations()->leave($user, $conversation->id());
        }

        $this->assertSame(0, ConversationModel::query()->count());
        $this->assertSame(0, ConversationMessageModel::query()->count());
        $stored = app(ConversationReportRepositoryInterface::class)->find($report->id());
        $this->assertInstanceOf(ConversationReport::class, $stored);
        $this->assertTrue($stored->isOpen());
        $this->assertSame([['number' => 1, 'text' => 'Hola, ¿quedamos el viernes?']], array_map(
            static fn (array $entry): array => ['number' => $entry['number'], 'text' => $entry['text']],
            $stored->context(),
        ));
        // And it is still shown and settled from the moderation queue
        $moderator = $this->anAccount(self::MODERATE);
        $this->assertCount(1, app(ConversationReportService::class)->open($moderator, 10));
        $this->assertSame(1, app(ConversationReportService::class)->resolve($moderator, $report->id(), ReportResolution::NoAction));
    }

    public function test_anonymizing_the_author_only_changes_the_name_the_copy_paints(): void
    {
        $this->ana->update(['name' => 'Ana Pérez', 'display_name' => null]);
        $conversation = $this->aConversation($this->ana, $this->luis);
        $first = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 1, 1)[0];
        $report = app(ConversationReportService::class)->report($this->luis, $first->id(), ReportReason::Offensive, null);
        $before = app(ConversationReportRepositoryInterface::class)->find($report->id());
        $this->assertInstanceOf(ConversationReport::class, $before);
        $this->assertSame('Ana Pérez', app(ConversationPresenter::class)->report($before)['authorName']);

        app(UserServiceInterface::class)->anonymize($this->ana->id);

        $after = app(ConversationReportRepositoryInterface::class)->find($report->id());
        $this->assertInstanceOf(ConversationReport::class, $after);
        // The stored copy is the same: text, numbers and the author's id
        $this->assertSame($before->context(), $after->context());
        $this->assertSame(strtolower($this->ana->id), $after->messageAuthorId());
        // Only the name painted changes
        $shown = app(ConversationPresenter::class)->report($after);
        $this->assertSame('Anónimo', $shown['authorName']);
        $this->assertSame('Anónimo', $shown['context'][0]['authorName']);
        $this->assertSame('Hola, ¿quedamos el viernes?', $shown['context'][0]['text']);
    }

    public function test_the_mail_escapes_the_title_people_wrote(): void
    {
        $conversation = $this->conversations()->start($this->ana, '*Urgente* [pincha](https://example.test)', [$this->luis->id], $this->messageDocument());
        $message = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 1, 1)[0];

        $mail = (new ConversationMessageNotification($conversation->id()->value, strtolower($this->ana->id), $message->id()->value))->toMail($this->luis);

        $line = (string) $mail->introLines[0];
        $this->assertStringNotContainsString('[pincha](https://example.test)', $line);
        $this->assertStringContainsString('\\*Urgente\\*', $line);
        $this->assertStringNotContainsString('quedamos', implode(' ', array_map(strval(...), $mail->introLines)));
    }

    public function test_only_people_in_the_conversation_report_and_never_their_own_message(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        $first = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 1, 1)[0];

        try {
            app(ConversationReportService::class)->report($this->eva, $first->id(), ReportReason::Spam, null);
            $this->fail('Someone outside reported a private message');
        } catch (ConversationNotFoundException) {
        }

        try {
            app(ConversationReportService::class)->report($this->ana, $first->id(), ReportReason::Spam, null);
            $this->fail('Someone reported their own message');
        } catch (ConversationRefusedException $exception) {
            $this->assertSame(ConversationRefusedException::NOT_ALLOWED, $exception->reason);
        }
    }

    public function test_whoever_moderates_the_whole_forum_settles_and_hiding_empties_the_message_for_everyone(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        $first = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 1, 1)[0];
        app(ConversationReportService::class)->report($this->luis, $first->id(), ReportReason::Offensive, null);
        $report = app(ConversationReportService::class)->open($this->anAccount(self::MODERATE), 10)[0];

        // Moderating a category is not enough, nor is being suspended while moderating
        $categoryModerator = $this->anAccount();
        $this->aCategoryModeratedBy($categoryModerator);
        $suspendedModerator = $this->anAccount(self::MODERATE);
        app(SuspensionService::class)->suspend(UserModel::factory()->admin()->create(), $suspendedModerator->id, 'Abuso', null);
        app()->forgetScopedInstances();
        foreach ([$categoryModerator, $suspendedModerator, $this->ana, $this->eva] as $user) {
            $this->assertSame([], app(ConversationReportService::class)->open($user, 10));
            try {
                app(ConversationReportService::class)->resolve($user, $report->id(), ReportResolution::Hidden);
                $this->fail('Someone who does not moderate the whole forum settled a report of a private message');
            } catch (ForumActionDeniedException) {
            }
        }

        $moderator = $this->anAccount(self::MODERATE);
        $this->assertSame(1, app(ConversationReportService::class)->resolve($moderator, $report->id(), ReportResolution::Hidden, 'Insultos'));

        $row = ConversationMessageModel::query()->findOrFail($first->id()->value);
        $this->assertNull($row->body);
        $this->assertNotNull($row->hidden_at);
        $this->assertSame('Insultos', $row->hidden_reason);
        $this->assertSame([], app(ConversationReportService::class)->open($moderator, 10));
        $this->assertDatabaseHas('forum_moderation_log', ['action' => 'private_message_hidden', 'subject_user_id' => strtolower($this->ana->id)]);
        // And moderation still cannot open the conversation
        $this->assertFalse(app(ConversationAccess::class)->canRead($moderator, $conversation->id()));
    }

    public function test_notices_go_to_the_others_without_what_was_written_and_one_mail_until_read(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        $this->conversations()->reply($this->ana, $conversation->id(), $this->messageDocument('Un secreto'));

        $notices = app(SiteNotificationServiceInterface::class);
        $this->assertSame(1, $notices->unreadCount($this->luis));
        $this->assertSame(0, $notices->unreadCount($this->ana));
        $item = $notices->latest($this->luis, 5)[0];
        $this->assertStringContainsString('Partida del viernes', $item->title);
        $this->assertStringNotContainsString('secreto', $item->title.$item->body);
        $this->assertStringNotContainsString('quedamos', $item->title.$item->body);

        // One mail between the two messages; another only once he read
        $mails = $this->mailsTo($this->luis, fn () => null);
        $this->assertSame(0, $mails);
        $this->conversations()->markRead($this->luis, $conversation->id(), 2);
        $this->assertSame(1, $this->mailsTo($this->luis, fn () => $this->conversations()->reply($this->ana, $conversation->id(), $this->messageDocument('Tres'))));
        $this->assertSame(0, $this->mailsTo($this->luis, fn () => $this->conversations()->reply($this->ana, $conversation->id(), $this->messageDocument('Cuatro'))));

        // Whoever left hears nothing more
        $this->conversations()->leave($this->luis, $conversation->id());
        $this->conversations()->add($this->ana, $conversation->id(), $this->eva->id);
        $this->assertSame(0, $this->mailsTo($this->luis, fn () => $this->conversations()->reply($this->ana, $conversation->id(), $this->messageDocument('Cinco'))));
    }

    public function test_private_conversations_are_never_indexed(): void
    {
        $conversation = $this->aConversation($this->ana, $this->luis);
        $this->conversations()->reply($this->luis, $conversation->id(), $this->messageDocument('Nadie debe encontrar esto'));

        $this->assertSame(0, DB::table('search_documents')->count());
        foreach (app(SearchSourceRegistryInterface::class)->indexedSources() as $source) {
            $this->assertStringNotContainsString('conversation', $source->type());
        }
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function mailsTo(UserModel $user, callable $action): int
    {
        $count = 0;
        Event::listen(NotificationSent::class, static function (NotificationSent $event) use ($user, &$count): void {
            if ($event->channel === 'mail' && $event->notification instanceof ConversationMessageNotification && $event->notifiable->getKey() === $user->getKey()) {
                $count++;
            }
        });
        $action();

        return $count;
    }

    private function aCategoryModeratedBy(UserModel $user): void
    {
        $role = RoleModel::query()->create(['name' => 'mods-'.strtolower(uniqid()), 'display_name' => 'Mods']);
        $user->roles()->attach($role->id);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($user);
        app(CategoryRepositoryInterface::class)->save(new Category(
            CategoryId::generate(),
            'Mesas',
            new CategorySlug('mesas'),
            viewAudience: CategoryAudience::signedIn(),
            writeAudience: CategoryAudience::signedIn(),
            moderators: new CategoryModerators([$role->id]),
        ));
    }
}
