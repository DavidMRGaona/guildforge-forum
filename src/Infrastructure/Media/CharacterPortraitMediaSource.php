<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Media;

use App\Application\Media\Services\MediaFileIdResolverInterface;
use App\Infrastructure\Media\Sources\EloquentColumnMediaSource;
use Illuminate\Database\Eloquent\Model;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CharacterModel;
use Override;
use WeakMap;

/**
 * The portraits of forum characters (forum/characters/). A file is in use while a character,
 * retired or not, shows it. Characters have no panel page, so no edit link.
 *
 * Others see a portrait only next to the posts that speak as its character, so the media library
 * shows it only while one of those posts is read by every member (forum#155, PublicForumImages). A
 * character nobody has seen yet, one that speaks only in narrower categories (the shared characters
 * of a campaign space, say) or in hidden posts is marked hidden, and so is its name.
 *
 * Like the posts' source it does not declare ShowsMediaPreviews yet: the core would show its unused
 * files, the replaced or left portraits of shared characters among them (core#843).
 */
final class CharacterPortraitMediaSource extends EloquentColumnMediaSource
{
    public const string TYPE = 'forum.characters';

    /**
     * The categories every member reads, while a walk lasts: read once for all its records.
     *
     * @var list<string>|null
     */
    private ?array $walkCategoryIds = null;

    /**
     * Whether each record was seen, so its title and its flag ask once.
     *
     * @var WeakMap<Model, bool>
     */
    private WeakMap $seen;

    public function __construct(
        MediaFileIdResolverInterface $resolver,
        private readonly PublicForumImages $public,
    ) {
        parent::__construct($resolver);
        $this->seen = new WeakMap;
    }

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

    #[Override]
    public function usages(): iterable
    {
        $this->walkCategoryIds = $this->public->categoryIds();

        try {
            yield from parent::usages();
        } finally {
            $this->walkCategoryIds = null;
        }
    }

    protected function titleOf(Model $record): string
    {
        if (! $this->isSeen($record)) {
            return __('forum::messages.media.private_character_title');
        }

        $name = $record->getAttribute('name');

        return __('forum::messages.media.character_title', ['name' => is_string($name) ? $name : '']);
    }

    #[Override]
    protected function isHidden(Model $record): bool
    {
        return ! $this->isSeen($record);
    }

    protected function editUrlOf(Model $record, object $user): ?string
    {
        return null;
    }

    private function isSeen(Model $record): bool
    {
        if (isset($this->seen[$record])) {
            return $this->seen[$record];
        }

        $id = $record->getKey();
        $seen = is_string($id) && $this->public->isCharacterSeen($id, $this->walkCategoryIds ?? $this->public->categoryIds());
        $this->seen[$record] = $seen;

        return $seen;
    }
}
