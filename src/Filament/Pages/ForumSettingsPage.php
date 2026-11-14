<?php

declare(strict_types=1);

namespace Modules\Forum\Filament\Pages;

use App\Application\Modules\Services\ModuleContextServiceInterface;
use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use App\Filament\Clusters\SettingsCluster;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Concerns\HasKnowledgeGuideAction;
use App\Filament\Concerns\SafeModuleNavigation;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Forum\Application\Embeds\EmbedProviders;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Domain\Enums\CharacterAccessMode;
use Modules\Forum\Domain\Enums\PostImageType;
use Modules\Forum\Domain\Enums\SubjectConversationMode;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;
use Modules\Forum\Infrastructure\Services\ForumSettings;
use Throwable;

/**
 * The forum settings, open to forum:settings.manage (admins hold every permission), in the
 * «Configuración» cluster in the slot kept for the forum (125).
 *
 * @property Schema $form
 */
final class ForumSettingsPage extends Page implements HasForms
{
    use ChecksPermissions;
    use HasKnowledgeGuideAction;
    use InteractsWithForms;
    use SafeModuleNavigation;

    public const string PERMISSION = 'forum:settings.manage';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 125;

    protected static ?string $slug = 'forum-settings';

    protected string $view = 'filament.pages.simple-settings';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('forum::messages.settings.navigation');
    }

    public function getTitle(): string
    {
        return __('forum::messages.settings.title');
    }

    public static function canAccess(): bool
    {
        return self::userCan(self::PERMISSION);
    }

    public function mount(ModuleSettingsStoreInterface $settingsStore, ModuleContextServiceInterface $moduleContext): void
    {
        // The shipped defaults with the saved values on top, so keys added by a release show
        // their defaults instead of loading empty and being saved off
        $state = $settingsStore->effective('forum', $moduleContext->modulePath('forum'));

        // Each offered type shows what applies to it: hidden until chosen, and for an unknown value
        $modes = is_array($state['subject_conversations'] ?? null) ? $state['subject_conversations'] : [];
        foreach (array_keys($this->subjectTypes()) as $type) {
            $mode = $modes[$type] ?? null;
            $known = is_string($mode) ? SubjectConversationMode::tryFrom($mode) : null;
            $modes[$type] = ($known ?? SubjectConversationMode::Hidden)->value;
        }
        $state['subject_conversations'] = $modes;

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('forum::messages.settings.lists'))
                    ->schema([
                        $this->pageSizeField('discussions_per_page'),
                        $this->pageSizeField('posts_per_page'),
                    ]),
                Section::make(__('forum::messages.settings.posting'))
                    ->schema([
                        TextInput::make('posts_per_minute')
                            ->label(__('forum::messages.settings.posts_per_minute'))
                            ->helperText(__('forum::messages.settings.posts_per_minute_help', ['min' => ForumSettings::MIN_POSTS_PER_MINUTE, 'max' => ForumSettings::MAX_POSTS_PER_MINUTE]))
                            ->integer()
                            ->minValue(ForumSettings::MIN_POSTS_PER_MINUTE)
                            ->maxValue(ForumSettings::MAX_POSTS_PER_MINUTE)
                            ->required()
                            ->default(ForumSettings::DEFAULT_POSTS_PER_MINUTE),
                        $this->boundedField('discussions_per_hour', ForumSettings::MIN_DISCUSSIONS_PER_HOUR, ForumSettings::MAX_DISCUSSIONS_PER_HOUR, ForumSettings::DEFAULT_DISCUSSIONS_PER_HOUR)
                            ->helperText(__('forum::messages.settings.discussions_per_hour_help', ['min' => ForumSettings::MIN_DISCUSSIONS_PER_HOUR, 'max' => ForumSettings::MAX_DISCUSSIONS_PER_HOUR])),
                    ]),
                Section::make(__('forum::messages.settings.subject_conversations'))
                    ->description(__('forum::messages.settings.subject_conversations_help'))
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2, '2xl' => 3])
                            ->schema(array_map(
                                fn (string $type, string $label): Select => Select::make("subject_conversations.{$type}")
                                    ->label($label)
                                    ->options($this->subjectConversationOptions())
                                    ->native(false)
                                    ->selectablePlaceholder(false)
                                    ->required()
                                    ->in(array_keys($this->subjectConversationOptions()))
                                    ->default(SubjectConversationMode::Hidden->value),
                                array_keys($this->subjectTypes()),
                                $this->subjectTypes(),
                            )),
                    ]),
                Section::make(__('forum::messages.settings.announcements'))
                    ->description(__('forum::messages.settings.announcements_help'))
                    ->schema([
                        CheckboxList::make('announced_categories')
                            ->label(__('forum::messages.settings.announced_categories'))
                            ->helperText(__('forum::messages.settings.announced_categories_help'))
                            ->options(self::announceableCategoryOptions())
                            // Only offered categories are kept: one that turned private since is dropped,
                            // and so is anything forged (the announcer checks the rules again anyway)
                            ->dehydrateStateUsing(static fn (mixed $state): array => is_array($state)
                                ? array_values(array_intersect(array_filter($state, is_string(...)), array_keys(self::announceableCategoryOptions())))
                                : [])
                            ->columns(2)
                            ->default([]),
                    ]),
                Section::make(__('forum::messages.settings.characters'))
                    ->description(__('forum::messages.settings.characters_help'))
                    ->schema([
                        Select::make('characters_access')
                            ->label(__('forum::messages.settings.characters_access'))
                            ->helperText(__('forum::messages.settings.characters_access_help'))
                            ->options($this->characterAccessOptions())
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->in(array_keys($this->characterAccessOptions()))
                            ->default(CharacterAccessMode::Everyone->value),
                    ]),
                Section::make(__('forum::messages.settings.images'))
                    ->description(__('forum::messages.settings.images_help'))
                    ->schema([
                        CheckboxList::make('image_types')
                            ->label(__('forum::messages.settings.image_types'))
                            ->helperText(__('forum::messages.settings.image_types_help'))
                            ->options(self::imageTypeOptions())
                            // Filament checks only single selects against their options
                            ->rules([static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                if (! is_array($value) || array_diff($value, array_keys(self::imageTypeOptions())) !== []) {
                                    $fail(__('forum::messages.settings.image_types_invalid'));
                                }
                            }])
                            // At 1024 px the settings cluster leaves the form narrow: two columns
                            // until the form is wide enough for four
                            ->columns(['default' => 2, 'xl' => 4])
                            ->default(array_keys(self::imageTypeOptions())),
                        $this->boundedField('image_max_kilobytes', ForumSettings::MIN_IMAGE_MAX_KILOBYTES, ForumSettings::MAX_IMAGE_MAX_KILOBYTES, ForumSettings::DEFAULT_IMAGE_MAX_KILOBYTES),
                        $this->boundedField('image_max_dimension', ForumSettings::MIN_IMAGE_MAX_DIMENSION, ForumSettings::MAX_IMAGE_MAX_DIMENSION, ForumSettings::DEFAULT_IMAGE_MAX_DIMENSION)
                            ->helperText(__('forum::messages.settings.image_max_dimension_help').' '.__('forum::messages.settings.range_help', ['min' => ForumSettings::MIN_IMAGE_MAX_DIMENSION, 'max' => ForumSettings::MAX_IMAGE_MAX_DIMENSION])),
                        $this->boundedField('images_per_post', ForumSettings::MIN_IMAGES_PER_POST, ForumSettings::MAX_IMAGES_PER_POST, ForumSettings::DEFAULT_IMAGES_PER_POST),
                        $this->boundedField('image_uploads_per_hour', ForumSettings::MIN_IMAGE_UPLOADS_PER_HOUR, ForumSettings::MAX_IMAGE_UPLOADS_PER_HOUR, ForumSettings::DEFAULT_IMAGE_UPLOADS_PER_HOUR)
                            ->helperText(__('forum::messages.settings.image_uploads_per_hour_help', ['min' => ForumSettings::MIN_IMAGE_UPLOADS_PER_HOUR, 'max' => ForumSettings::MAX_IMAGE_UPLOADS_PER_HOUR])),
                        TagsInput::make('external_image_hosts')
                            ->label(__('forum::messages.settings.external_image_hosts'))
                            ->helperText(__('forum::messages.settings.external_image_hosts_help'))
                            ->placeholder(__('forum::messages.settings.external_image_hosts_placeholder'))
                            ->splitKeys([',', ' ', 'Enter'])
                            ->reorderable(false)
                            // Kept as typed would let «https://imgur.com» through, which never matches
                            ->dehydrateStateUsing(static fn (mixed $state): array => is_array($state)
                                ? array_values(array_unique(array_map(static fn (mixed $host): string => strtolower(trim(is_string($host) ? $host : '')), $state)))
                                : [])
                            ->rules([static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                if (! is_array($value) || count($value) > ForumSettings::MAX_EXTERNAL_IMAGE_HOSTS) {
                                    $fail(__('forum::messages.settings.external_image_hosts_invalid'));

                                    return;
                                }
                                foreach ($value as $host) {
                                    if (! is_string($host) || ! RemoteUrl::isHostName(strtolower(trim($host)))) {
                                        $fail(__('forum::messages.settings.external_image_hosts_invalid'));

                                        return;
                                    }
                                }
                            }])
                            ->default(ForumSettings::DEFAULT_EXTERNAL_IMAGE_HOSTS),
                    ]),
                Section::make(__('forum::messages.settings.embeds'))
                    ->description(__('forum::messages.settings.embeds_help').' '.__('forum::messages.settings.embed_cookie_category_help'))
                    ->schema([
                        CheckboxList::make('embed_services')
                            ->label(__('forum::messages.settings.embed_services'))
                            ->helperText(__('forum::messages.settings.embed_services_help'))
                            ->options(self::embedServiceOptions())
                            // Filament checks only single selects against their options
                            ->rules([static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                if (! is_array($value) || array_diff($value, array_keys(self::embedServiceOptions())) !== []) {
                                    $fail(__('forum::messages.settings.embed_services_invalid'));
                                }
                            }])
                            // At 1024 px the settings cluster leaves the form narrow: two columns
                            // until the form is wide enough for four
                            ->columns(['default' => 2, 'xl' => 4])
                            ->default(array_keys(self::embedServiceOptions())),
                        Grid::make(['default' => 1, 'sm' => 2, '2xl' => 4])
                            ->schema(array_map(
                                static fn (string $key, string $label): TextInput => TextInput::make("embed_cookie_categories.{$key}")
                                    ->label(__('forum::messages.settings.embed_cookie_category', ['service' => $label]))
                                    ->required()
                                    ->maxLength(40)
                                    ->regex(ForumSettings::COOKIE_CATEGORY_PATTERN)
                                    ->validationMessages(['regex' => __('forum::messages.settings.embed_cookie_category_invalid')])
                                    ->default(ForumSettings::DEFAULT_EMBED_COOKIE_CATEGORY),
                                array_keys(self::embedServiceOptions()),
                                self::embedServiceOptions(),
                            )),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(ModuleManagerServiceInterface $moduleManager, ModuleSettingsStoreInterface $settingsStore): void
    {
        // Livewire methods can be called without the page's button
        abort_unless(self::canAccess(), 403);

        // Keys this page does not show keep their stored values, and so do the conversation
        // modes of types no source offers now (their module is off): they apply again when it is on
        $stored = $settingsStore->stored('forum');
        $state = $this->form->getState();
        $state['subject_conversations'] = array_merge(
            is_array($stored['subject_conversations'] ?? null) ? $stored['subject_conversations'] : [],
            is_array($state['subject_conversations'] ?? null) ? $state['subject_conversations'] : [],
        );
        $moduleManager->updateSettings(new ModuleName('forum'), array_merge($stored, $state));

        Notification::make()
            ->title(__('forum::messages.settings.saved'))
            ->success()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([$this->knowledgeGuideAction('forum.panel.settings')]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('common.save'))
                ->submit('save'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function characterAccessOptions(): array
    {
        $options = [];
        foreach (CharacterAccessMode::cases() as $mode) {
            $options[$mode->value] = $mode->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function subjectConversationOptions(): array
    {
        $options = [];
        foreach (SubjectConversationMode::cases() as $mode) {
            $options[$mode->value] = $mode->label();
        }

        return $options;
    }

    /**
     * The publication types some source offers now (only their pages exist), named in the forum's
     * words when it knows them and by their type otherwise. A registry that fails offers none.
     *
     * @return array<string, string>
     */
    private function subjectTypes(): array
    {
        try {
            $types = array_keys(app(PublicationSourceRegistryInterface::class)->sources());
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $labels = [];
        foreach ($types as $type) {
            $key = "forum::messages.settings.subject_types.{$type}";
            $label = __($key);
            $labels[$type] = is_string($label) && $label !== $key ? $label : $type;
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private static function embedServiceOptions(): array
    {
        $options = [];
        foreach (EmbedProviders::builtIn()->all() as $provider) {
            $options[$provider->key()] = $provider->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function imageTypeOptions(): array
    {
        $options = [];
        foreach (PostImageType::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    /**
     * Categories whose new discussions may be announced: those guests can read that no
     * publication fills (tables, campaigns and events announce through their own records).
     *
     * @return array<string, string>
     */
    private static function announceableCategoryOptions(): array
    {
        $policy = app(CategoryAccessPolicy::class);
        $options = [];
        foreach (app(CategoryRepositoryInterface::class)->all() as $category) {
            if ($category->isSystem() || $category->isPublicationSpace() || ! $policy->canView(null, $category)) {
                continue;
            }

            $options[$category->id()->value] = $category->name();
        }

        return $options;
    }

    private function boundedField(string $key, int $min, int $max, int $default): TextInput
    {
        return TextInput::make($key)
            ->label(__("forum::messages.settings.{$key}"))
            ->helperText(__('forum::messages.settings.range_help', ['min' => $min, 'max' => $max]))
            ->integer()
            ->minValue($min)
            ->maxValue($max)
            ->required()
            ->default($default);
    }

    private function pageSizeField(string $key): TextInput
    {
        return TextInput::make($key)
            ->label(__("forum::messages.settings.{$key}"))
            ->helperText(__('forum::messages.settings.page_size_help', ['min' => ForumSettings::MIN_PAGE_SIZE, 'max' => ForumSettings::MAX_PAGE_SIZE]))
            ->integer()
            ->minValue(ForumSettings::MIN_PAGE_SIZE)
            ->maxValue(ForumSettings::MAX_PAGE_SIZE)
            ->required()
            ->default(ForumSettings::DEFAULT_PAGE_SIZE);
    }
}
