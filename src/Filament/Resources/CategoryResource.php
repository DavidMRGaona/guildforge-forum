<?php

declare(strict_types=1);

namespace Modules\Forum\Filament\Resources;

use App\Filament\Actions\RowDetailsAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Support\PanelNavigationGroup;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryAudienceKind;
use Modules\Forum\Domain\Enums\CategoryIcon;
use Modules\Forum\Domain\Enums\ReadOnlyOrigin;
use Modules\Forum\Domain\Enums\RosterScope;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Filament\Resources\CategoryResource\Pages\CreateCategory;
use Modules\Forum\Filament\Resources\CategoryResource\Pages\EditCategory;
use Modules\Forum\Filament\Resources\CategoryResource\Pages\ListCategories;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentCategoryRepository;
use Throwable;

/**
 * The forum's categories: two levels, dragged into order, each with who reads it, who writes in
 * it and who moderates it. Saving goes through CategoryManager (tree and rules are checked
 * there); deleting moves the discussions first and needs a box ticked.
 */
final class CategoryResource extends BaseResource
{
    protected static ?string $model = CategoryModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'forum-categories';

    public static function getNavigationGroup(): string
    {
        return PanelNavigationGroup::Communication->getLabel();
    }

    public static function getNavigationLabel(): string
    {
        return __('forum::messages.categories.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('forum::messages.categories.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forum::messages.categories.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forum::messages.categories.sections.general'))
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('name')
                        ->label(__('forum::messages.categories.fields.name'))
                        ->required()
                        ->maxLength(Category::MAX_NAME_LENGTH)
                        ->live(onBlur: true)
                        ->afterStateUpdated(static function (Set $set, Get $get, ?string $state, string $operation): void {
                            if ($operation === 'create' && blank($get('slug'))) {
                                try {
                                    $set('slug', CategorySlug::fromName($state ?? '')->value);
                                } catch (InvalidCategoryException) {
                                    // Nothing to make it from: left for the person to type
                                }
                            }
                        }),
                    TextInput::make('slug')
                        ->label(__('forum::messages.categories.fields.slug'))
                        ->helperText(__('forum::messages.categories.fields.slug_help'))
                        ->required()
                        ->maxLength(CategorySlug::MAX_LENGTH)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->unique(CategoryModel::class, 'slug', ignoreRecord: true),
                    Textarea::make('description')
                        ->label(__('forum::messages.categories.fields.description'))
                        ->maxLength(Category::MAX_DESCRIPTION_LENGTH)
                        ->rows(2)
                        ->columnSpanFull(),
                    Select::make('parent_id')
                        ->label(__('forum::messages.categories.fields.parent'))
                        ->helperText(__('forum::messages.categories.fields.parent_help'))
                        ->native(false)
                        ->options(fn (?CategoryModel $record): array => self::parentOptions($record?->id))
                        ->disabled(fn (?CategoryModel $record): bool => $record?->children()->exists() === true)
                        ->placeholder(__('forum::messages.categories.fields.parent_none')),
                ]),
            Section::make(__('forum::messages.categories.sections.appearance'))
                ->columns(['md' => 2])
                ->schema([
                    ColorPicker::make('color')
                        ->label(__('forum::messages.categories.fields.color'))
                        ->regex('/^#[0-9a-fA-F]{6}$/'),
                    Select::make('icon')
                        ->label(__('forum::messages.categories.fields.icon'))
                        ->native(false)
                        ->options(self::iconOptions()),
                ]),
            Section::make(__('forum::messages.categories.sections.access'))
                ->description(__('forum::messages.categories.sections.access_help'))
                ->columns(['md' => 2])
                ->schema([
                    Text::make(fn (?CategoryModel $record): string => __('forum::messages.categories.fields.space_notice'))
                        ->visible(fn (?CategoryModel $record): bool => self::isSpace($record))
                        ->columnSpanFull(),
                    Select::make('view_kind')
                        ->label(__('forum::messages.categories.fields.view'))
                        ->native(false)
                        ->required()
                        ->live()
                        ->default(CategoryAudienceKind::Everyone->value)
                        ->options(fn (?CategoryModel $record): array => self::audienceOptions($record, guests: true)),
                    self::rolesField('view_role_ids', 'view_kind'),
                    Select::make('write_kind')
                        ->label(__('forum::messages.categories.fields.write'))
                        ->helperText(__('forum::messages.categories.fields.write_help'))
                        ->native(false)
                        ->required()
                        ->live()
                        ->default(CategoryAudienceKind::SignedIn->value)
                        ->options(fn (?CategoryModel $record): array => self::audienceOptions($record, guests: false)),
                    self::rolesField('write_role_ids', 'write_kind'),
                ]),
            Section::make(__('forum::messages.categories.sections.moderation'))
                ->description(__('forum::messages.categories.sections.moderation_help'))
                ->columns(['md' => 2])
                ->schema([
                    Select::make('moderator_role_ids')
                        ->label(__('forum::messages.categories.fields.moderator_roles'))
                        ->multiple()
                        ->native(false)
                        ->options(self::roleOptions(...))
                        ->rules([fn (): Closure => self::onlyOptions(self::roleOptions(...))]),
                    Select::make('moderator_roster')
                        ->label(__('forum::messages.categories.fields.moderator_roster'))
                        ->native(false)
                        ->options([
                            RosterScope::Leaders->value => __('forum::messages.audiences.roster_leaders'),
                            RosterScope::Roster->value => __('forum::messages.audiences.roster'),
                        ])
                        ->visible(fn (?CategoryModel $record): bool => self::isSpace($record)),
                    TextInput::make('approval_threshold')
                        ->label(__('forum::messages.categories.fields.approval_threshold'))
                        ->helperText(__('forum::messages.categories.fields.approval_threshold_help'))
                        ->integer()
                        ->minValue(0)
                        ->maxValue(Category::MAX_APPROVAL_THRESHOLD)
                        ->required()
                        ->default(0),
                    // Switching it off lifts the forum's mark too; switching it on makes it moderation's
                    Toggle::make('read_only')
                        ->label(__('forum::messages.categories.fields.read_only'))
                        ->helperText(fn (?CategoryModel $record): string => $record?->read_only_by === ReadOnlyOrigin::System->value
                            ? __('forum::messages.categories.fields.read_only_by_system')
                            : __('forum::messages.categories.fields.read_only_help'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Categories that depend on a module that is missing (PublicationAvailability: «Mesas» or
     * «Campañas» without a module offering game tables, a campaign's space, and their subcategories)
     * are left out of the list, the edit page, and the parent and move options. Nothing is deleted:
     * they come back with the module.
     *
     * @return Builder<CategoryModel>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<CategoryModel> $query */
        $query = parent::getEloquentQuery();

        return $query->whereKeyNot(self::unavailableIds());
    }

    /**
     * @return list<string>
     */
    public static function unavailableIds(): array
    {
        $policy = app(CategoryAccessPolicy::class);
        $ids = [];
        foreach (app(CategoryRepositoryInterface::class)->all() as $category) {
            if (! $policy->isAvailable($category)) {
                $ids[] = $category->id()->value;
            }
        }

        return $ids;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => EloquentCategoryRepository::inTreeOrder($query->with('parent')))
            ->columns([
                TextColumn::make('name')
                    ->label(__('forum::messages.categories.fields.name'))
                    ->formatStateUsing(fn (string $state, CategoryModel $record): string => $record->parent_id === null ? $state : "— {$state}")
                    ->description(fn (CategoryModel $record): ?string => $record->description)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('parent.name')
                    ->label(__('forum::messages.categories.fields.parent'))
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('discussion_count')
                    ->label(__('forum::messages.categories.fields.discussions'))
                    ->alignCenter()
                    ->visibleFrom('md'),
                TextColumn::make('view_rule')
                    ->label(__('forum::messages.categories.fields.view'))
                    ->badge()
                    ->color('gray')
                    ->getStateUsing(fn (CategoryModel $record): string => self::audienceLabel($record->view_rule))
                    ->visibleFrom('xl'),
                TextColumn::make('system_key')
                    ->label(__('forum::messages.categories.fields.system'))
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '' : __('forum::messages.categories.system_badge'))
                    ->placeholder('—')
                    ->visibleFrom('xl'),
            ])
            ->reorderable('position')
            // Dragging saves the order of the rows on screen: all of them must be on screen
            ->paginated(false)
            // One menu: several labelled actions would widen the table past its container
            ->recordActions([
                ActionGroup::make([
                    RowDetailsAction::make(),
                    EditAction::make(),
                    self::deleteAction(),
                ]),
            ]);
    }

    /**
     * Irreversible: a box to tick, and a category to move its discussions into while it has any.
     * The categories the forum relies on and those with subcategories do not offer it.
     */
    public static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('forum::messages.categories.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('forum::messages.categories.actions.delete_heading'))
            ->modalDescription(__('forum::messages.categories.actions.delete_description'))
            ->hidden(fn (CategoryModel $record): bool => ! self::canDelete($record))
            ->schema(fn (CategoryModel $record): array => [
                Select::make('move_to')
                    ->label(__('forum::messages.categories.actions.move_to'))
                    ->helperText(__('forum::messages.categories.actions.move_to_help'))
                    ->native(false)
                    ->options(fn (): array => self::moveTargets($record->id))
                    ->required()
                    ->in(fn (): array => array_keys(self::moveTargets($record->id)))
                    ->visible($record->discussions()->exists()),
                Checkbox::make('confirmation')
                    ->label(__('forum::messages.categories.actions.delete_confirmation'))
                    ->accepted()
                    ->required(),
            ])
            ->action(function (CategoryModel $record, array $data, Action $action): void {
                // Livewire actions can be called without the menu
                abort_unless(self::canDelete($record), 403);

                $moveTo = is_string($data['move_to'] ?? null) ? CategoryId::fromString($data['move_to']) : null;

                try {
                    app(CategoryManager::class)->delete(CategoryId::fromString($record->id), $moveTo);
                } catch (InvalidCategoryException $exception) {
                    Notification::make()->danger()->title(__("forum::messages.categories.errors.{$exception->reason}"))->send();
                    $action->halt();
                }

                Notification::make()->success()->title(__('forum::messages.categories.actions.deleted'))->send();
                $action->success();
            })
            ->successRedirectUrl(fn (): string => self::getUrl());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }

    /**
     * Top-level categories a category may go under (never itself).
     *
     * @return array<string, string>
     */
    public static function parentOptions(?string $exceptId): array
    {
        /** @var array<string, string> */
        return self::getEloquentQuery()
            ->whereNull('parent_id')
            ->when($exceptId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptId))
            ->orderBy('position')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function moveTargets(string $exceptId): array
    {
        /** @var array<string, string> */
        return EloquentCategoryRepository::inTreeOrder(self::getEloquentQuery()->whereKeyNot($exceptId))
            ->pluck('forum_categories.name', 'forum_categories.id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        /** @var array<string, string> */
        return RoleModel::query()->orderBy('display_name')->pluck('display_name', 'id')->all();
    }

    private static function rolesField(string $name, string $kindField): Select
    {
        return Select::make($name)
            ->label(__('forum::messages.categories.fields.roles'))
            ->multiple()
            ->native(false)
            ->options(self::roleOptions(...))
            ->visible(fn (Get $get): bool => $get($kindField) === CategoryAudienceKind::Roles->value)
            ->required(fn (Get $get): bool => $get($kindField) === CategoryAudienceKind::Roles->value)
            ->rules([fn (): Closure => self::onlyOptions(self::roleOptions(...))]);
    }

    /**
     * Roster rules only in a category that belongs to a publication; guests only for reading.
     *
     * @return array<string, string>
     */
    private static function audienceOptions(?CategoryModel $record, bool $guests): array
    {
        $options = [];
        foreach (CategoryAudienceKind::cases() as $kind) {
            if (($kind === CategoryAudienceKind::Everyone && ! $guests) || ($kind->needsSubject() && ! self::isSpace($record))) {
                continue;
            }

            $options[$kind->value] = __("forum::messages.audiences.{$kind->value}");
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function iconOptions(): array
    {
        $options = [];
        foreach (CategoryIcon::cases() as $icon) {
            $options[$icon->value] = __("forum::messages.icons.{$icon->name}");
        }

        return $options;
    }

    private static function audienceLabel(mixed $rule): string
    {
        $kind = is_array($rule) && is_string($rule['kind'] ?? null) ? CategoryAudienceKind::tryFrom($rule['kind']) : null;

        // An unreadable rule shows to nobody: the badge says so
        return $kind === null ? __('forum::messages.audiences.unreadable') : __("forum::messages.audiences.{$kind->value}");
    }

    private static function isSpace(?CategoryModel $record): bool
    {
        return $record?->subject_type !== null && $record->subject_id !== null;
    }

    /**
     * Filament checks only single selects against their options: multiple ones go through this.
     *
     * @param  Closure(): array<string, string>  $options
     */
    private static function onlyOptions(Closure $options): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($options): void {
            try {
                $allowed = array_map(strval(...), array_keys($options()));
            } catch (Throwable) {
                $allowed = [];
            }

            foreach (is_array($value) ? $value : [$value] as $item) {
                if (! in_array($item, $allowed, true)) {
                    $fail(__('validation.in', ['attribute' => $attribute]));

                    return;
                }
            }
        };
    }
}
