<?php

declare(strict_types=1);

namespace Modules\Forum\Filament\Pages;

use App\Application\Modules\Services\ModuleContextServiceInterface;
use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use App\Filament\Clusters\SettingsCluster;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Concerns\SafeModuleNavigation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Forum\Infrastructure\Services\ForumSettings;

/**
 * The forum settings, open to forum:settings.manage (admins hold every permission), in the
 * «Configuración» cluster in the slot kept for the forum (125).
 *
 * @property Schema $form
 */
final class ForumSettingsPage extends Page implements HasForms
{
    use ChecksPermissions;
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
        $this->form->fill($settingsStore->effective('forum', $moduleContext->modulePath('forum')));
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
                    ]),
            ])
            ->statePath('data');
    }

    public function save(ModuleManagerServiceInterface $moduleManager, ModuleSettingsStoreInterface $settingsStore): void
    {
        // Livewire methods can be called without the page's button
        abort_unless(self::canAccess(), 403);

        // Keys this page does not show keep their stored values
        $moduleManager->updateSettings(new ModuleName('forum'), array_merge($settingsStore->stored('forum'), $this->form->getState()));

        Notification::make()
            ->title(__('forum::messages.settings.saved'))
            ->success()
            ->send();
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
