<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Filament;

use App\Filament\Actions\RowDetailsAction;
use App\Filament\Pages\KnowledgeBasePage;
use App\Filament\Support\PanelNavigationGroup;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Livewire;
use Modules\Forum\Application\Conversations\ConversationReportService;
use Modules\Forum\Application\Conversations\ConversationService;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ReportService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Filament\Pages\ForumModerationPage;
use Modules\Forum\Filament\Widgets\ForumActivityWidget;
use Modules\Forum\ForumServiceProvider;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ConversationMessageModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ConversationReportModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ModerationLogModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ReportModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\SuspensionModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

final class ForumModerationPageTest extends ModuleTestCase
{
    use BuildsForumContent;
    use InteractsWithModulePanel;
    use UsesUtcStorage;

    private const string MODERATE = 'forum:forum.moderate';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->registerOnAdminPanel(pages: [ForumModerationPage::class]);
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
    }

    public function test_the_provider_registers_the_page_and_the_widget(): void
    {
        $provider = new ForumServiceProvider($this->app);

        $this->assertContains(ForumModerationPage::class, $provider->registerFilamentPages());
        $this->assertContains(ForumActivityWidget::class, $provider->registerFilamentWidgets());
    }

    public function test_it_sits_in_the_communication_group_next_to_the_categories(): void
    {
        $this->assertSame(PanelNavigationGroup::Communication->getLabel(), ForumModerationPage::getNavigationGroup());
        $this->assertSame(35, ForumModerationPage::getNavigationSort());
        $this->assertSame('Moderación del foro', ForumModerationPage::getNavigationLabel());
    }

    public function test_only_whoever_moderates_the_whole_forum_opens_it(): void
    {
        $this->actingAs($this->panelUser(['forum:categories.manage']));
        $this->assertFalse(ForumModerationPage::canAccess());
        Livewire::test(ForumModerationPage::class)->assertForbidden();
        $this->assertFalse(ForumActivityWidget::canView());

        $this->actingAs($this->panelUser([self::MODERATE]));
        $this->assertTrue(ForumModerationPage::canAccess());
        Livewire::test(ForumModerationPage::class)->assertOk();
        $this->assertTrue(ForumActivityWidget::canView());
    }

    public function test_the_pending_tab_lists_what_awaits_approval_and_the_badge_counts_it(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));
        [$discussion] = $this->aDiscussion($this->general);
        $pending = $this->aReply($discussion, '2026-11-10 18:30:00', needsApproval: true);
        $this->aReply($discussion, '2026-11-10 18:40:00');

        Livewire::test(ForumModerationPage::class)
            ->set('activeTab', ForumModerationPage::TAB_PENDING)
            ->assertCanSeeTableRecords([PostModel::query()->findOrFail($pending->id()->value)])
            ->assertCountTableRecords(1);

        $this->assertSame('1', ForumModerationPage::getNavigationBadge());
    }

    public function test_approving_from_the_panel_publishes_the_post_and_logs_who(): void
    {
        $moderator = $this->panelUser([self::MODERATE]);
        $this->actingAs($moderator);
        [$discussion] = $this->aDiscussion($this->general);
        $pending = $this->aReply($discussion, '2026-11-10 18:30:00', needsApproval: true);

        Livewire::test(ForumModerationPage::class)
            ->set('activeTab', ForumModerationPage::TAB_PENDING)
            ->callTableAction('approve', PostModel::query()->findOrFail($pending->id()->value))
            ->assertNotified();

        $this->assertSame(2, app(PostRepositoryInterface::class)->findOrFail($pending->id())->number()?->value);
        $entry = ModerationLogModel::query()->sole();
        $this->assertSame([ModerationAction::PostApproved->value, strtolower($moderator->id)], [$entry->action, $entry->moderator_id]);
    }

    public function test_rejecting_asks_to_tick_the_box_first(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));
        [$discussion, $first] = $this->aDiscussion($this->general, needsApproval: true);
        $record = PostModel::query()->findOrFail($first->id()->value);

        Livewire::test(ForumModerationPage::class)
            ->set('activeTab', ForumModerationPage::TAB_PENDING)
            ->callTableAction('reject', $record, ['reason' => 'Spam', 'confirmation' => false])
            ->assertHasTableActionErrors(['confirmation']);
        $this->assertNotNull(PostModel::query()->find($first->id()->value));

        Livewire::test(ForumModerationPage::class)
            ->set('activeTab', ForumModerationPage::TAB_PENDING)
            ->callTableAction('reject', $record, ['reason' => 'Spam', 'confirmation' => true])
            ->assertHasNoTableActionErrors();

        // The first post of a waiting discussion goes with its discussion
        $this->assertNull(app(DiscussionRepositoryInterface::class)->find($discussion->id()));
        $this->assertSame('Spam', ModerationLogModel::query()->sole()->reason);
    }

    public function test_the_history_tab_lists_the_log_newest_first(): void
    {
        $moderator = $this->panelUser([self::MODERATE]);
        $this->actingAs($moderator);
        [$discussion] = $this->aDiscussion($this->general);
        app(ForumModeration::class)->pin($moderator, $discussion->id());
        app(ForumModeration::class)->close($moderator, $discussion->id(), 'Resuelto');

        $page = Livewire::test(ForumModerationPage::class)->set('activeTab', ForumModerationPage::TAB_HISTORY);

        $page->assertCountTableRecords(2)
            ->assertTableColumnFormattedStateSet('action', 'Tema cerrado', ModerationLogModel::query()->where('action', ModerationAction::DiscussionClosed->value)->sole())
            ->filterTable('action', ModerationAction::DiscussionPinned->value)
            ->assertCountTableRecords(1);
    }

    public function test_the_history_says_where_split_and_merged_posts_went(): void
    {
        $moderator = $this->panelUser([self::MODERATE]);
        $this->actingAs($moderator);
        [$discussion] = $this->aDiscussion($this->general);
        $this->aReply($discussion, '2026-11-10 18:10:00');
        $this->aReply($discussion, '2026-11-10 18:20:00');
        $split = app(ForumModeration::class)->split($moderator, $discussion->id(), [2, 3], 'Otro asunto', $this->general->id());
        [$into] = $this->aDiscussion($this->general, '2026-11-11 10:00:00', title: 'Destino');
        app(ForumModeration::class)->merge($moderator, $split->id(), $into->id());

        Livewire::test(ForumModerationPage::class)->set('activeTab', ForumModerationPage::TAB_HISTORY)
            ->assertTableColumnFormattedStateSet('action', 'Mensajes separados', ModerationLogModel::query()->where('action', ModerationAction::DiscussionSplit->value)->sole())
            ->assertTableColumnStateSet('details', '2 mensajes (2, 3) a «Otro asunto» (tema 2)', ModerationLogModel::query()->where('action', ModerationAction::DiscussionSplit->value)->sole())
            ->assertTableColumnStateSet('details', '2 mensajes a «Destino» (tema 3)', ModerationLogModel::query()->where('action', ModerationAction::DiscussionMerged->value)->sole());
    }

    public function test_a_link_opens_its_tab(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));

        Livewire::withQueryParams(['pestana' => ForumModerationPage::TAB_PENDING])
            ->test(ForumModerationPage::class)
            ->assertSet('activeTab', ForumModerationPage::TAB_PENDING);

        Livewire::withQueryParams(['pestana' => 'todo'])
            ->test(ForumModerationPage::class)
            ->assertSet('activeTab', ForumModerationPage::TAB_REPORTS);
    }

    public function test_an_unknown_tab_falls_back_to_the_reports(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));

        Livewire::test(ForumModerationPage::class)
            ->assertSet('activeTab', ForumModerationPage::TAB_REPORTS)
            ->set('activeTab', 'everything')
            ->assertSet('activeTab', ForumModerationPage::TAB_REPORTS);
    }

    public function test_the_reports_tab_hides_a_reported_post_and_settles_its_reports(): void
    {
        $moderator = $this->panelUser([self::MODERATE]);
        $this->actingAs($moderator);
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->aReply($discussion, '2026-11-10 18:30:00');
        app(ReportService::class)->report($this->anAccount(), $reply->id(), ReportReason::Spam, 'Enlaces a una tienda');
        app(ReportService::class)->report($this->anAccount(), $reply->id(), ReportReason::Offensive, null);
        $this->assertSame('2', ForumModerationPage::getNavigationBadge());

        $page = Livewire::test(ForumModerationPage::class)->assertCountTableRecords(2);
        $page->callTableAction('hide', ReportModel::query()->whereNull('resolution')->firstOrFail(), ['reason' => 'Spam'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PostStatus::Hidden, app(PostRepositoryInterface::class)->findOrFail($reply->id())->status());
        $this->assertSame(0, ReportModel::query()->whereNull('resolution')->count());
        $this->assertNull(ForumModerationPage::getNavigationBadge());
    }

    public function test_settling_without_action_leaves_the_post_as_it_was(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->aReply($discussion, '2026-11-10 18:30:00');
        app(ReportService::class)->report($this->anAccount(), $reply->id(), ReportReason::OffTopic, null);

        Livewire::test(ForumModerationPage::class)
            ->callTableAction('no_action', ReportModel::query()->sole())
            ->assertHasNoTableActionErrors();

        $this->assertSame(PostStatus::Visible, app(PostRepositoryInterface::class)->findOrFail($reply->id())->status());
        $this->assertSame('no_action', ReportModel::query()->sole()->resolution);
    }

    public function test_the_private_tab_shows_the_copy_of_a_reported_private_message_and_hides_it(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));
        $ana = $this->anAccount();
        $luis = $this->anAccount();
        $conversation = app(ConversationService::class)->start($ana, 'Privada', [$luis->id], ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Algo feo']]]]]);
        $message = app(ConversationRepositoryInterface::class)->entries($conversation->id(), 1, 1)[0];
        app(ConversationReportService::class)->report($luis, $message->id(), ReportReason::Offensive, null);
        $this->assertSame('1', ForumModerationPage::getNavigationBadge());

        $page = Livewire::test(ForumModerationPage::class)->set('activeTab', ForumModerationPage::TAB_PRIVATE)
            ->assertCountTableRecords(1)
            ->assertSee('Algo feo')
            // The conversation's title is never shown: only the copy
            ->assertDontSee('Privada');
        $page->callTableAction('hide_private', ConversationReportModel::query()->sole(), ['reason' => 'Insultos'])
            ->assertHasNoTableActionErrors();

        $this->assertNotNull(ConversationMessageModel::query()->findOrFail($message->id()->value)->hidden_at);
        $this->assertSame('hidden', ConversationReportModel::query()->sole()->resolution);
        $this->assertNull(ForumModerationPage::getNavigationBadge());
    }

    public function test_suspending_from_the_header_and_lifting_from_the_row(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));
        $member = $this->anAccount();

        Livewire::test(ForumModerationPage::class)
            ->callAction('suspend', ['user' => $member->id, 'reason' => 'Insultos', 'days' => '7'])
            ->assertHasNoActionErrors();

        $suspension = SuspensionModel::query()->sole();
        $this->assertSame(strtolower($member->id), $suspension->user_id);
        $this->assertNotNull($suspension->ends_at);

        Livewire::test(ForumModerationPage::class)
            ->set('activeTab', ForumModerationPage::TAB_SUSPENSIONS)
            ->assertCountTableRecords(1)
            ->callTableAction('lift', $suspension, ['reason' => 'Se ha disculpado'])
            ->assertHasNoTableActionErrors();

        $this->assertNotNull(SuspensionModel::query()->sole()->lifted_at);
    }

    public function test_suspending_needs_a_reason(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));

        Livewire::test(ForumModerationPage::class)
            ->callAction('suspend', ['user' => $this->anAccount()->id, 'reason' => '', 'days' => 'forever'])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(0, SuspensionModel::query()->count());
    }

    public function test_report_rows_put_hiding_first_and_the_rest_in_one_menu_starting_with_the_details(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));
        $actions = $this->table(ForumModerationPage::TAB_REPORTS)->getRecordActions();

        $this->assertCount(2, $actions);
        $this->assertInstanceOf(Action::class, $actions[0]);
        $this->assertSame('hide', $actions[0]->getName());
        $this->assertInstanceOf(ActionGroup::class, $actions[1]);
        $this->assertSame(RowDetailsAction::NAME, array_key_first($actions[1]->getFlatActions()));
    }

    public function test_suspension_rows_offer_one_menu_starting_with_the_details(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));
        $actions = $this->table(ForumModerationPage::TAB_SUSPENSIONS)->getRecordActions();

        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ActionGroup::class, $actions[0]);
        $this->assertSame([RowDetailsAction::NAME, 'lift'], array_keys($actions[0]->getFlatActions()));
    }

    public function test_pending_rows_put_approve_first_and_the_rest_in_one_menu_starting_with_the_details(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));
        $actions = $this->table(ForumModerationPage::TAB_PENDING)->getRecordActions();

        $this->assertCount(2, $actions);
        $this->assertInstanceOf(Action::class, $actions[0]);
        $this->assertSame('approve', $actions[0]->getName());
        $this->assertInstanceOf(ActionGroup::class, $actions[1]);
        $this->assertSame(RowDetailsAction::NAME, array_key_first($actions[1]->getFlatActions()));
    }

    public function test_history_rows_offer_only_the_details(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));
        $actions = $this->table(ForumModerationPage::TAB_HISTORY)->getRecordActions();

        $this->assertCount(1, $actions);
        $this->assertInstanceOf(Action::class, $actions[0]);
        $this->assertSame(RowDetailsAction::NAME, $actions[0]->getName());
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function columnBreakpoints(): iterable
    {
        yield 'reports excerpt' => [ForumModerationPage::TAB_REPORTS, 'post.plain_text', null];
        yield 'reports reason' => [ForumModerationPage::TAB_REPORTS, 'reason', 'sm'];
        yield 'reports reporter' => [ForumModerationPage::TAB_REPORTS, 'reporter.name', 'md'];
        yield 'reports when' => [ForumModerationPage::TAB_REPORTS, 'created_at', 'xl'];
        yield 'reports note' => [ForumModerationPage::TAB_REPORTS, 'note', '2xl'];
        yield 'private excerpt' => [ForumModerationPage::TAB_PRIVATE, 'excerpt', null];
        yield 'private author' => [ForumModerationPage::TAB_PRIVATE, 'messageAuthor.name', 'sm'];
        yield 'private reason' => [ForumModerationPage::TAB_PRIVATE, 'reason', 'md'];
        yield 'private when' => [ForumModerationPage::TAB_PRIVATE, 'created_at', 'xl'];
        yield 'private note' => [ForumModerationPage::TAB_PRIVATE, 'note', '2xl'];
        yield 'suspensions person' => [ForumModerationPage::TAB_SUSPENSIONS, 'user.name', null];
        yield 'suspensions state' => [ForumModerationPage::TAB_SUSPENSIONS, 'state', 'sm'];
        yield 'suspensions end' => [ForumModerationPage::TAB_SUSPENSIONS, 'ends_at', 'md'];
        yield 'suspensions by' => [ForumModerationPage::TAB_SUSPENSIONS, 'suspender.name', 'xl'];
        yield 'suspensions reason' => [ForumModerationPage::TAB_SUSPENSIONS, 'reason', '2xl'];
        yield 'pending discussion' => [ForumModerationPage::TAB_PENDING, 'discussion.title', null];
        yield 'pending author' => [ForumModerationPage::TAB_PENDING, 'author.name', 'sm'];
        yield 'pending written' => [ForumModerationPage::TAB_PENDING, 'created_at', 'md'];
        yield 'pending excerpt' => [ForumModerationPage::TAB_PENDING, 'plain_text', 'xl'];
        yield 'pending category' => [ForumModerationPage::TAB_PENDING, 'discussion.category.name', '2xl'];
        yield 'history action' => [ForumModerationPage::TAB_HISTORY, 'action', null];
        yield 'history moderator' => [ForumModerationPage::TAB_HISTORY, 'moderator.name', 'sm'];
        yield 'history when' => [ForumModerationPage::TAB_HISTORY, 'created_at', 'md'];
        yield 'history subject' => [ForumModerationPage::TAB_HISTORY, 'subjectUser.name', 'xl'];
        yield 'history reason' => [ForumModerationPage::TAB_HISTORY, 'reason', 'xl'];
        yield 'history details' => [ForumModerationPage::TAB_HISTORY, 'details', '2xl'];
    }

    #[DataProvider('columnBreakpoints')]
    public function test_secondary_columns_appear_from_the_width_where_they_fit(string $tab, string $column, ?string $breakpoint): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));

        $this->assertSame($breakpoint, $this->table($tab)->getColumn($column)?->getVisibleFrom());
    }

    public function test_the_main_columns_wrap_instead_of_widening_the_table(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));

        foreach ([[ForumModerationPage::TAB_REPORTS, 'post.plain_text'], [ForumModerationPage::TAB_PRIVATE, 'excerpt'], [ForumModerationPage::TAB_PENDING, 'discussion.title'], [ForumModerationPage::TAB_SUSPENSIONS, 'user.name'], [ForumModerationPage::TAB_HISTORY, 'action']] as [$tab, $name]) {
            $column = $this->table($tab)->getColumn($name);
            $this->assertInstanceOf(TextColumn::class, $column);
            $this->assertTrue($column->canWrap(), $name);
        }
    }

    public function test_the_widget_counts_the_last_30_days_and_what_awaits(): void
    {
        $this->actingAs($this->panelUser([self::MODERATE]));
        $this->travelTo('2026-11-20 12:00:00');
        [$old] = $this->aDiscussion($this->general, '2026-10-01 10:00:00');
        [$recent] = $this->aDiscussion($this->general, '2026-11-15 10:00:00');
        $this->aReply($recent, '2026-11-15 11:00:00');
        $this->aReply($recent, '2026-11-15 12:00:00', needsApproval: true);
        $reported = $this->aReply($old, '2026-10-02 10:00:00');
        app(ReportService::class)->report($this->anAccount(), $reported->id(), ReportReason::Spam, null);

        Livewire::test(ForumActivityWidget::class)
            ->assertSeeInOrder(['Temas nuevos', '1', 'Mensajes nuevos', '2', 'Denuncias pendientes', '1', 'Pendientes de aprobación', '1']);
    }

    private function table(string $tab): Table
    {
        $page = Livewire::test(ForumModerationPage::class)->set('activeTab', $tab);
        $instance = $page->instance();
        $this->assertInstanceOf(HasTable::class, $instance);

        return $instance->getTable();
    }

    public function test_the_header_offers_the_moderation_guide_next_to_suspending(): void
    {
        $this->artisanCommand('knowledge:sync')->assertSuccessful()->run();
        $this->actingAs($this->panelUser([self::MODERATE]));

        Livewire::test(ForumModerationPage::class)
            ->assertActionVisible('contextualHelp')
            ->assertActionHasUrl('contextualHelp', KnowledgeBasePage::getUrl(['guia' => 'forum.panel.moderation']));
    }

    /**
     * A panel user (the editor state gives admin.access) with a role holding these permissions.
     *
     * @param  list<string>  $permissionKeys
     */
    private function panelUser(array $permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester-'.strtolower(uniqid()), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'forum', 'action' => 'any', 'module' => 'forum'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
