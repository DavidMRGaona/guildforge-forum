<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Support\Str;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Exceptions\CharacterNotFoundException;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CharacterModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostRevisionModel;

final class EloquentCharacterRepository implements CharacterRepositoryInterface
{
    public function save(Character $character): void
    {
        $model = CharacterModel::query()->find($character->id()->value) ?? new CharacterModel(['id' => $character->id()->value]);
        $model->fill([
            'owner_id' => $character->ownerId(),
            'name' => $character->name(),
            'description' => $character->description(),
            'portrait_path' => $character->portraitPath(),
            'category_id' => $character->categoryId()?->value,
            'retired_at' => $character->retiredAt(),
            'retirement_locked' => $character->isRetirementLocked(),
            'created_at' => $character->createdAt(),
            'updated_at' => $character->updatedAt(),
        ]);
        // The entity keeps its own times
        $model->timestamps = false;
        $model->save();
    }

    public function find(CharacterId $id): ?Character
    {
        $model = CharacterModel::query()->find($id->value);

        return $model instanceof CharacterModel ? $this->toEntity($model) : null;
    }

    public function findOrFail(CharacterId $id): Character
    {
        return $this->find($id) ?? throw CharacterNotFoundException::withId($id->value);
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = [];
        foreach (CharacterModel::query()->whereKey(array_values(array_unique(array_map(static fn (CharacterId $id): string => $id->value, $ids))))->get() as $model) {
            $found[$model->id] = $this->toEntity($model);
        }

        return $found;
    }

    public function ownedBy(string $accountId): array
    {
        $owner = AccountId::normalize($accountId);
        if ($owner === null) {
            return [];
        }

        return array_values(CharacterModel::query()
            ->where('owner_id', $owner)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (CharacterModel $model): Character => $this->toEntity($model))
            ->all());
    }

    public function countOwnedBy(string $accountId): int
    {
        $owner = AccountId::normalize($accountId);

        return $owner === null ? 0 : CharacterModel::query()->where('owner_id', $owner)->count();
    }

    public function sharedIn(CategoryId $space): array
    {
        return array_values(CharacterModel::query()
            ->whereNull('owner_id')
            ->where('category_id', $space->value)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (CharacterModel $model): Character => $this->toEntity($model))
            ->all());
    }

    public function countSharedIn(CategoryId $space): int
    {
        return CharacterModel::query()->whereNull('owner_id')->where('category_id', $space->value)->count();
    }

    public function hasPosts(CharacterId $id): bool
    {
        return PostModel::query()->where('character_id', $id->value)->exists()
            || PostRevisionModel::query()->where('character_id', $id->value)->exists();
    }

    public function idsWithPosts(array $ids): array
    {
        $values = array_values(array_unique(array_map(static fn (CharacterId $id): string => $id->value, $ids)));
        if ($values === []) {
            return [];
        }

        $found = [
            ...PostModel::query()->whereIn('character_id', $values)->distinct()->pluck('character_id')->all(),
            ...PostRevisionModel::query()->whereIn('character_id', $values)->distinct()->pluck('character_id')->all(),
        ];

        return array_values(array_unique(array_filter($found, is_string(...))));
    }

    public function lastUsedIn(DiscussionId $discussionId, string $accountId): ?CharacterId
    {
        $author = AccountId::normalize($accountId);
        if ($author === null) {
            return null;
        }

        $characterId = PostModel::query()
            ->where('discussion_id', $discussionId->value)
            ->where('author_id', $author)
            ->whereNotNull('character_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('character_id');

        return is_string($characterId) && Str::isUuid($characterId) ? CharacterId::fromString($characterId) : null;
    }

    public function delete(CharacterId $id): void
    {
        // Through the model, so the media library is told
        CharacterModel::query()->find($id->value)?->delete();
    }

    private function toEntity(CharacterModel $model): Character
    {
        return new Character(
            id: CharacterId::fromString($model->id),
            ownerId: $model->owner_id,
            name: $model->name,
            description: $model->description,
            portraitPath: $model->portrait_path,
            categoryId: $model->category_id === null ? null : CategoryId::fromString($model->category_id),
            createdAt: $model->created_at->toDateTimeImmutable(),
            updatedAt: $model->updated_at->toDateTimeImmutable(),
            retiredAt: $model->retired_at?->toDateTimeImmutable(),
            retirementLocked: $model->retirement_locked,
        );
    }
}
