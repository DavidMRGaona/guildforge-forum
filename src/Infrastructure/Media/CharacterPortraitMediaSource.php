<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Media;

use App\Infrastructure\Media\Sources\EloquentColumnMediaSource;
use Illuminate\Database\Eloquent\Model;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CharacterModel;

/**
 * The portraits of forum characters (forum/characters/). A file is in use while a character,
 * retired or not, shows it. Characters have no panel page, so no edit link.
 */
final class CharacterPortraitMediaSource extends EloquentColumnMediaSource
{
    public const string TYPE = 'forum.characters';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('forum::messages.media.characters_source');
    }

    public function folders(): array
    {
        return [Character::PORTRAIT_FOLDER];
    }

    public function excludedSubfolders(): array
    {
        return [];
    }

    protected function modelClass(): string
    {
        return CharacterModel::class;
    }

    protected function column(): string
    {
        return 'portrait_path';
    }

    protected function role(): string
    {
        return __('forum::messages.media.portrait_role');
    }

    protected function titleOf(Model $record): string
    {
        $name = $record->getAttribute('name');

        return __('forum::messages.media.character_title', ['name' => is_string($name) ? $name : '']);
    }

    protected function editUrlOf(Model $record, object $user): ?string
    {
        return null;
    }
}
