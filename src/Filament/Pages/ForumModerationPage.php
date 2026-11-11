<?php

declare(strict_types=1);

namespace Modules\Forum\Filament\Pages;

use App\Application\Services\TimezoneServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Filament\Forms\Components\Select;
use Modules\Forum\Application\Services\ReportService;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Entities\Suspension;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\ValueObjects\SuspensionId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ReportModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\SuspensionModel;
use App\Filament\Actions\RowDetailsAction;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\PanelNavigationGroup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Domain\Entities\ModerationLogEntry;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ModerationLogModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Livewire\Attributes\Url;
use Throwable;

/**
 * The forum's moderation in the panel, for whoever moderates the whole forum: open reports,
 * what awaits approval, suspensions and the moderation log, one tab each. Approving and rejecting go through
 * ForumModeration, like in the forum, so the rules, the locks and the log are the same.
 * Rejecting deletes for good: it asks to tick a box first.
 */
final class ForumModerationPage extends Page implements HasTable
{
    use ChecksPermissions;
    use InteractsWithTable;

    public const string TAB_REPORTS = 'reports';

    public const string TAB_PENDING = 'pending';

    public const string TAB_SUSPENSIONS = 'suspensions';

    public const string TAB_HISTORY = 'history';

    public const array TABS = [self::TAB_REPORTS, self::TAB_PENDING, self::TAB_SUSPENSIONS, self::TAB_HISTORY];

    /** Suspensions offered from the panel, in days; null has no end */
    public const array SUSPENSION_DAYS = [1, 3, 7, 30, 90];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'moderacion-del-foro';

    /** In the address (?pestana=pending), so a link opens the right tab */
    #[Url(as: 'pestana')]
    public ?string $activeTab = self::TAB_REPORTS;

    public static function getNavigationGroup(): string
    {
        return PanelNavigationGroup::Communication->getLabel();
    }

    public static function getNavigationLabel(): string
    {
        return __('forum::messages.moderation.navigation');
    }

    public function getTitle(): string
    {
        return __('forum::messages.moderation.title');
    }

    public static function canAccess(): bool
    {
        return self::userCan(CategoryAccessPolicy::MODERATE_FORUM);
    }

    /**
     * Open reports and posts awaiting approval together: what is waiting for someone.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = self::openReports() + self::pendingPosts();

        return $waiting > 0 ? (string) $waiting : null;
    }

    private static function openReports(): int
    {
        return ReportModel::query()->whereNull('resolution')->count();
    }

    private static function pendingPosts(): int
    {
        return PostModel::query()->where('status', PostStatus::Pending->value)->count();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->suspendAction()];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->livewireProperty('activeTab')
                ->contained(false)
                ->tabs([
                    self::TAB_REPORTS => Tab::make(__('forum::messages.moderation.tabs.reports'))
                        ->icon('heroicon-o-flag')
                        ->badge(fn (): ?string => self::openReports() > 0 ? (string) self::openReports() : null),
                    self::TAB_PENDING => Tab::make(__('forum::messages.moderation.tabs.pending'))
                        ->icon('heroicon-o-clock')
                        ->badge(fn (): ?string => self::pendingPosts() > 0 ? (string) self::pendingPosts() : null),
                    self::TAB_SUSPENSIONS => Tab::make(__('forum::messages.moderation.tabs.suspensions'))
                        ->icon('heroicon-o-no-symbol'),
                    self::TAB_HISTORY => Tab::make(__('forum::messages.moderation.tabs.history'))
                        ->icon('heroicon-o-clipboard-document-list'),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function mount(): void
    {
        $this->updatedActiveTab();
    }

    public function updatedActiveTab(): void
    {
        if (! in_array($this->activeTab, self::TABS, true)) {
            $this->activeTab = self::TAB_REPORTS;
        }

        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return match ($this->activeTab) {
            self::TAB_PENDING => $this->pendingTable($table),
            self::TAB_SUSPENSIONS => $this->suspensionsTable($table),
            self::TAB_HISTORY => $this->historyTable($table),
            default => $this->reportsTable($table),
        };
    }

    /**
     * Open reports, oldest first. Settling one settles every open report of the same post.
     */
    private function reportsTable(Table $table): Table
    {
        return $table
            ->query(ReportModel::query()->whereNull('resolution')->with(['reporter', 'post.discussion']))
            ->defaultSort('created_at')
            ->emptyStateHeading(__('forum::messages.moderation.panel.empty_reports'))
            ->columns([
                TextColumn::make('post.plain_text')
                    ->label(__('forum::messages.moderation.fields.excerpt'))
                    ->formatStateUsing(static fn (string $state): string => Str::limit(trim($state), 140))
                    ->description(fn (ReportModel $record): ?string => $record->post?->discussion?->title)
                    ->wrap(),
                TextColumn::make('reason')
                    ->label(__('forum::messages.moderation.fields.reasons'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::label('forum::messages.reports.reasons.'.(ReportReason::tryFrom($state) ?? ReportReason::Other)->value, $state))
                    ->visibleFrom('sm'),
                TextColumn::make('reporter.name')
                    ->label(__('forum::messages.moderation.fields.person'))
                    ->placeholder(__('forum::messages.moderation.deleted_account'))
                    ->visibleFrom('md'),
                TextColumn::make('created_at')
                    ->label(__('forum::messages.moderation.fields.reported_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->visibleFrom('xl'),
                TextColumn::make('note')
                    ->label(__('forum::messages.moderation.fields.notes'))
                    ->placeholder(__('forum::messages.moderation.nobody'))
                    ->wrap()
                    ->visibleFrom('2xl'),
            ])
            ->recordActions([
                $this->settleAction('hide', ReportResolution::Hidden)
                    ->label(__('forum::messages.moderation.panel.hide_post'))
                    ->icon('heroicon-o-eye-slash')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->schema([$this->reasonField(Post::MAX_HIDDEN_REASON_LENGTH)])
                    ->button()
                    ->labeledFrom('md'),
                ActionGroup::make([
                    RowDetailsAction::make(),
                    Action::make('open')
                        ->label(__('forum::messages.moderation.panel.open'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (ReportModel $record): ?string => $record->post instanceof PostModel ? $this->discussionUrl($record->post) : null)
                        ->openUrlInNewTab(),
                    $this->settleAction('no_action', ReportResolution::NoAction)
                        ->label(__('forum::messages.moderation.panel.no_action'))
                        ->icon('heroicon-o-check'),
                    $this->settleAction('edited', ReportResolution::Edited)
                        ->label(__('forum::messages.moderation.panel.edited'))
                        ->icon('heroicon-o-pencil-square'),
                ]),
            ]);
    }

    /**
     * Every suspension, the ones in force first. Lifting one lets the person write again.
     */
    private function suspensionsTable(Table $table): Table
    {
        return $table
            ->query(SuspensionModel::query()->with(['user', 'suspender']))
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('CASE WHEN lifted_at IS NULL THEN 0 ELSE 1 END')
                ->orderByDesc('created_at'))
            ->emptyStateHeading(__('forum::messages.moderation.panel.empty_suspensions'))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('forum::messages.moderation.fields.person'))
                    ->placeholder(__('forum::messages.moderation.deleted_account'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('state')
                    ->label(__('forum::messages.moderation.fields.state'))
                    ->badge()
                    ->state(fn (SuspensionModel $record): string => self::suspensionState($record))
                    ->formatStateUsing(static fn (string $state): string => self::label("forum::messages.moderation.suspension_states.{$state}", $state))
                    ->color(static fn (string $state): string => $state === 'active' ? 'danger' : 'gray')
                    ->visibleFrom('sm'),
                TextColumn::make('ends_at')
                    ->label(__('forum::messages.moderation.fields.ends_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('forum::messages.moderation.details.no_end'))
                    ->visibleFrom('md'),
                TextColumn::make('suspender.name')
                    ->label(__('forum::messages.moderation.fields.suspended_by'))
                    ->placeholder(__('forum::messages.moderation.deleted_account'))
                    ->visibleFrom('xl'),
                TextColumn::make('reason')
                    ->label(__('forum::messages.moderation.fields.reason'))
                    ->wrap()
                    ->visibleFrom('2xl'),
            ])
            ->recordActions([
                ActionGroup::make([
                    RowDetailsAction::make(),
                    $this->liftAction(),
                ]),
            ]);
    }

    /**
     * Posts awaiting approval, oldest first: the first post of a discussion that waits with it,
     * or a reply.
     */
    private function pendingTable(Table $table): Table
    {
        return $table
            ->query(PostModel::query()->where('status', PostStatus::Pending->value)->with(['author', 'discussion.category']))
            ->defaultSort('created_at')
            ->emptyStateHeading(__('forum::messages.moderation.panel.empty_pending'))
            ->columns([
                TextColumn::make('discussion.title')
                    ->label(__('forum::messages.moderation.fields.discussion'))
                    ->description(fn (PostModel $record): ?string => $record->discussion?->awaiting_approval === true ? __('forum::messages.moderation.fields.first_post') : null)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('author.name')
                    ->label(__('forum::messages.moderation.fields.author'))
                    ->placeholder(__('forum::messages.moderation.deleted_account'))
                    ->visibleFrom('sm'),
                TextColumn::make('plain_text')
                    ->label(__('forum::messages.moderation.fields.excerpt'))
                    ->formatStateUsing(static fn (string $state): string => Str::limit(trim($state), 140))
                    ->wrap()
                    ->visibleFrom('xl'),
                TextColumn::make('discussion.category.name')
                    ->label(__('forum::messages.moderation.fields.category'))
                    ->visibleFrom('2xl'),
                TextColumn::make('created_at')
                    ->label(__('forum::messages.moderation.fields.written_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                $this->approveAction()->button()->labeledFrom('md'),
                ActionGroup::make([
                    RowDetailsAction::make(),
                    Action::make('open')
                        ->label(__('forum::messages.moderation.panel.open'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (PostModel $record): ?string => $this->discussionUrl($record))
                        ->openUrlInNewTab(),
                    $this->rejectAction(),
                ]),
            ]);
    }

    /**
     * The moderation log, newest first. It is only read here: nothing changes or deletes it.
     */
    private function historyTable(Table $table): Table
    {
        return $table
            ->query(ModerationLogModel::query()->with(['moderator', 'subjectUser']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->emptyStateHeading(__('forum::messages.moderation.panel.empty_history'))
            ->columns([
                TextColumn::make('action')
                    ->label(__('forum::messages.moderation.fields.action'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::actionLabel($state))
                    ->description(fn (ModerationLogModel $record): ?string => self::discussionTitle($record))
                    ->wrap(),
                TextColumn::make('moderator.name')
                    ->label(__('forum::messages.moderation.fields.moderator'))
                    ->placeholder(__('forum::messages.moderation.deleted_account'))
                    ->visibleFrom('sm'),
                TextColumn::make('created_at')
                    ->label(__('forum::messages.moderation.fields.when'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('subjectUser.name')
                    ->label(__('forum::messages.moderation.fields.subject'))
                    ->placeholder(__('forum::messages.moderation.nobody'))
                    ->visibleFrom('xl'),
                TextColumn::make('reason')
                    ->label(__('forum::messages.moderation.fields.reason'))
                    ->placeholder(__('forum::messages.moderation.nobody'))
                    ->wrap()
                    ->visibleFrom('xl'),
                TextColumn::make('details')
                    ->label(__('forum::messages.moderation.fields.details'))
                    ->state(fn (ModerationLogModel $record): ?string => self::detailsOf($record))
                    ->placeholder(__('forum::messages.moderation.nobody'))
                    ->wrap()
                    ->visibleFrom('2xl'),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label(__('forum::messages.moderation.filters.action'))
                    ->options(self::actionOptions()),
            ])
            ->recordActions([
                RowDetailsAction::makeIconButton(),
            ]);
    }

    private function settleAction(string $name, ReportResolution $resolution): Action
    {
        return Action::make($name)
            ->action(function (ReportModel $record, array $data) use ($resolution): void {
                $reason = is_string($data['reason'] ?? null) && trim($data['reason']) !== '' ? $data['reason'] : null;
                $this->moderate(
                    fn (): int => app(ReportService::class)->resolve($this->moderator(), PostId::fromString($record->post_id), $resolution, $reason),
                    __($resolution === ReportResolution::Hidden ? 'forum::messages.moderation.panel.hidden' : 'forum::messages.moderation.panel.settled'),
                );
            });
    }

    private function suspendAction(): Action
    {
        return Action::make('suspend')
            ->label(__('forum::messages.moderation.panel.suspend'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->modalHeading(__('forum::messages.moderation.panel.suspend_heading'))
            ->modalDescription(__('forum::messages.moderation.panel.suspend_description'))
            ->schema([
                Select::make('user')
                    ->label(__('forum::messages.moderation.panel.suspend_user'))
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(static fn (string $search): array => UserModel::query()
                        ->whereNull('anonymized_at')
                        ->where(static fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'))
                        ->orderBy('name')
                        ->limit(20)
                        ->pluck('name', 'id')
                        ->all())
                    ->getOptionLabelUsing(static fn (mixed $value): ?string => is_string($value) ? UserModel::query()->find($value)?->name : null),
                Textarea::make('reason')
                    ->label(__('forum::messages.moderation.panel.suspend_reason'))
                    ->helperText(__('forum::messages.moderation.panel.suspend_reason_help'))
                    ->required()
                    ->maxLength(Suspension::MAX_REASON_LENGTH)
                    ->rows(2),
                Select::make('days')
                    ->label(__('forum::messages.moderation.panel.suspend_days'))
                    ->native(false)
                    ->required()
                    ->default('7')
                    ->options(self::durationOptions()),
            ])
            ->action(function (array $data): void {
                $days = $data['days'] ?? null;
                $endsAt = is_numeric($days) ? CarbonImmutable::now()->addDays((int) $days) : null;
                $this->moderate(
                    fn () => app(SuspensionService::class)->suspend($this->moderator(), (string) ($data['user'] ?? ''), (string) ($data['reason'] ?? ''), $endsAt),
                    __('forum::messages.moderation.panel.suspended'),
                );
            });
    }

    private function liftAction(): Action
    {
        return Action::make('lift')
            ->label(__('forum::messages.moderation.panel.lift'))
            ->icon('heroicon-o-lock-open')
            ->requiresConfirmation()
            ->modalHeading(__('forum::messages.moderation.panel.lift_heading'))
            ->schema([$this->reasonField(ModerationLogEntry::MAX_REASON_LENGTH)])
            ->visible(static fn (SuspensionModel $record): bool => self::suspensionState($record) === 'active')
            ->action(function (SuspensionModel $record, array $data): void {
                $reason = is_string($data['reason'] ?? null) && trim($data['reason']) !== '' ? $data['reason'] : null;
                $this->moderate(
                    fn () => app(SuspensionService::class)->lift($this->moderator(), SuspensionId::fromString($record->id), $reason),
                    __('forum::messages.moderation.panel.lifted'),
                );
            });
    }

    private function reasonField(int $max): Textarea
    {
        return Textarea::make('reason')
            ->label(__('forum::messages.moderation.panel.reason'))
            ->helperText(__('forum::messages.moderation.panel.reason_help'))
            ->maxLength($max)
            ->rows(2);
    }

    /**
     * @return array<string, string>
     */
    private static function durationOptions(): array
    {
        $options = [];
        foreach (self::SUSPENSION_DAYS as $days) {
            $options[(string) $days] = trans_choice('forum::messages.moderation.panel.suspend_days_option', $days, ['count' => $days]);
        }
        $options['forever'] = __('forum::messages.moderation.panel.suspend_forever');

        return $options;
    }

    private static function suspensionState(SuspensionModel $record): string
    {
        if ($record->lifted_at !== null) {
            return 'lifted';
        }

        return $record->ends_at !== null && $record->ends_at->isPast() ? 'ended' : 'active';
    }

    private static function label(string $key, string $fallback): string
    {
        $label = __($key);

        return is_string($label) ? $label : $fallback;
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('forum::messages.moderation.panel.approve'))
            ->icon('heroicon-o-check')
            ->color('success')
            ->action(function (PostModel $record): void {
                $this->moderate(fn () => app(ForumModeration::class)->approve($this->moderator(), PostId::fromString($record->id)), __('forum::messages.moderation.panel.approved'));
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('forum::messages.moderation.panel.reject'))
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('forum::messages.moderation.panel.reject_heading'))
            ->modalDescription(__('forum::messages.moderation.panel.reject_description'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('forum::messages.moderation.panel.reason'))
                    ->helperText(__('forum::messages.moderation.panel.reason_help'))
                    ->maxLength(ModerationLogEntry::MAX_REASON_LENGTH)
                    ->rows(2),
                // It cannot be undone: a second confirmation, as every irreversible action
                Checkbox::make('confirmation')
                    ->label(__('forum::messages.moderation.panel.reject_confirmation'))
                    ->accepted()
                    ->required(),
            ])
            ->action(function (PostModel $record, array $data): void {
                $reason = is_string($data['reason'] ?? null) ? $data['reason'] : null;
                $this->moderate(fn () => app(ForumModeration::class)->reject($this->moderator(), PostId::fromString($record->id), $reason), __('forum::messages.moderation.panel.rejected'));
            });
    }

    /**
     * Runs a moderation action and tells how it went. Livewire methods can be called without
     * the page, so the permission is checked again; and ForumModeration checks it once more.
     *
     * @param  callable(): mixed  $action
     */
    private function moderate(callable $action, string $done): void
    {
        abort_unless(self::canAccess(), 403);

        try {
            $action();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title(__('forum::messages.moderation.panel.failed'))->danger()->send();

            return;
        }

        Notification::make()->title($done)->success()->send();
    }

    private function moderator(): Authenticatable
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $user;
    }

    private function discussionUrl(PostModel $record): ?string
    {
        $discussion = $record->discussion;
        if ($discussion === null || $discussion->number === null) {
            return null;
        }

        return app(ForumUrlsInterface::class)->discussionFrom($discussion->number, $discussion->title);
    }

    /**
     * @return array<string, string>
     */
    private static function actionOptions(): array
    {
        $options = [];
        foreach (ModerationAction::cases() as $action) {
            $options[$action->value] = self::actionLabel($action->value);
        }

        return $options;
    }

    private static function actionLabel(string $value): string
    {
        $action = ModerationAction::tryFrom($value);
        $label = $action instanceof ModerationAction ? __("forum::messages.moderation.actions.{$action->value}") : $value;

        return is_string($label) ? $label : $value;
    }

    private static function discussionTitle(ModerationLogModel $record): ?string
    {
        $title = $record->details['discussion_title'] ?? null;
        $post = $record->details['post_number'] ?? null;

        if (! is_string($title)) {
            return null;
        }

        return is_int($post) ? $title.' · '.__('forum::messages.moderation.details.post_number', ['number' => $post]) : $title;
    }

    private static function detailsOf(ModerationLogModel $record): ?string
    {
        $details = $record->details ?? [];
        $from = $details['from'] ?? null;
        $to = $details['to'] ?? null;

        return match ($record->action) {
            ModerationAction::DiscussionRenamed->value => __('forum::messages.moderation.details.renamed', ['from' => (string) $from, 'to' => (string) $to]),
            ModerationAction::DiscussionMoved->value => __('forum::messages.moderation.details.moved', ['from' => (string) $from, 'to' => (string) $to]),
            ModerationAction::DiscussionDeleted->value => __('forum::messages.moderation.details.posts', ['count' => (int) ($details['posts'] ?? 0)]),
            ModerationAction::PostRejected->value => ($details['whole_discussion'] ?? false) === true ? __('forum::messages.moderation.details.whole_discussion') : null,
            ModerationAction::ReportResolved->value => __('forum::messages.moderation.details.resolved', [
                'resolution' => self::label('forum::messages.reports.resolutions.'.(ReportResolution::tryFrom((string) ($details['resolution'] ?? '')) ?? ReportResolution::NoAction)->value, ''),
                'count' => (int) ($details['reports'] ?? 0),
            ]),
            ModerationAction::UserSuspended->value => is_string($details['ends_at'] ?? null)
                ? __('forum::messages.moderation.details.until', ['date' => app(TimezoneServiceInterface::class)->format(new DateTimeImmutable($details['ends_at']))])
                : __('forum::messages.moderation.details.no_end'),
            default => null,
        };
    }
}
