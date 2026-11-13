<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Presenters;

use App\Application\Services\TimezoneServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\CharacterPortraitStorageInterface;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Services\CharacterRules;
use Modules\Forum\Application\Services\CharacterService;
use Modules\Forum\Application\Services\PostSpeakers;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Enums\PostImageType;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Http\Requests\SaveCharacterRequest;

/**
 * Characters as the pages draw them: the card of a post, the list of the profile tab and the
 * «Hablar como» picker of the editor. Portrait addresses come from the image storage; without a
 * portrait the initials show.
 */
final readonly class CharacterPresenter
{
    public function __construct(
        private CharacterPortraitStorageInterface $portraits,
        private CharacterRules $rules,
        private CharacterService $service,
        private PostSpeakers $speakers,
        private ForumSettingsInterface $settings,
        private TimezoneServiceInterface $timezone,
    ) {}

    /**
     * Who a post speaks as, and what its moderators may do with that character.
     *
     * @return array<string, mixed>
     */
    public function card(Character $character, bool $moderates): array
    {
        return [
            ...$this->basics($character),
            'description' => $character->description(),
            'isRetired' => $character->isRetired(),
            'actions' => $moderates ? $this->actions($character, owner: false) : null,
        ];
    }

    /**
     * The «Personajes» tab of the profile: the person's characters, the categories they may tie
     * one to, and the limits of the form.
     *
     * @return array<string, mixed>
     */
    public function profile(Authenticatable $owner): array
    {
        $characters = $this->service->ownedBy($owner);
        $categories = $this->rules->bindableCategories($owner);
        $names = [];
        foreach ($categories as $category) {
            $names[$category->id()->value] = $category->name();
        }
        $withPosts = array_flip($this->service->idsWithPosts($characters));

        return [
            'items' => array_map(fn (Character $character): array => [
                ...$this->basics($character),
                'description' => $character->description(),
                'categoryId' => $character->categoryId()?->value,
                'categoryName' => ($id = $character->categoryId()) instanceof CategoryId ? ($names[$id->value] ?? null) : null,
                'isRetired' => $character->isRetired(),
                'isRetirementLocked' => $character->isRetirementLocked(),
                'retiredLabel' => $character->retiredAt() instanceof \DateTimeImmutable ? $this->timezone->format($character->retiredAt()) : null,
                'hasPosts' => isset($withPosts[$character->id()->value]),
                'actions' => $this->actions($character, owner: true),
            ], $characters),
            ...$this->form($owner, $categories),
        ];
    }

    /**
     * The editor's «Hablar como»: the characters the person may write as in the category, the
     * one proposed (the last they used in the discussion) and whether they may create one.
     * Null when characters are off for them.
     *
     * @return array<string, mixed>|null
     */
    public function picker(?Authenticatable $user, Category $category, ?DiscussionId $discussionId = null): ?array
    {
        if (! $user instanceof Authenticatable || ! $this->rules->mayHaveCharacters($user)) {
            return null;
        }

        $usable = $this->speakers->usableIn($user, $category);
        $suggested = $discussionId instanceof DiscussionId ? $this->speakers->suggestedFor($user, $discussionId, $usable) : null;

        return [
            'items' => array_map($this->basics(...), $usable),
            'suggestedId' => $suggested?->value,
            // Where the post is written: a character created from the picker is chosen only
            // when it may speak here
            'categoryId' => $category->id()->value,
            ...$this->form($user, $this->rules->bindableCategories($user)),
        ];
    }

    /**
     * The characters the person may write as, with the categories each may be used in (for the
     * page that starts a discussion, where the category is chosen on the page). Null when
     * characters are off for them.
     *
     * @param  list<Category>  $categories  where the person may start a discussion
     * @return array<string, mixed>|null
     */
    public function pickerForCategories(?Authenticatable $user, array $categories): ?array
    {
        if (! $user instanceof Authenticatable || ! $this->rules->mayHaveCharacters($user)) {
            return null;
        }

        $items = [];
        foreach ($this->service->ownedBy($user) as $character) {
            $usableIn = array_values(array_map(
                static fn (Category $category): string => $category->id()->value,
                array_filter($categories, fn (Category $category): bool => $this->rules->usableIn($user, $character, $category)),
            ));
            if ($usableIn !== []) {
                $items[] = [...$this->basics($character), 'categoryIds' => $usableIn];
            }
        }

        return [
            'items' => $items,
            'suggestedId' => null,
            'categoryId' => null,
            ...$this->form($user, $this->rules->bindableCategories($user)),
        ];
    }

    /**
     * @return array{id: string, name: string, initials: string, portraitUrl: string|null}
     */
    public function basics(Character $character): array
    {
        $path = $character->portraitPath();

        return [
            'id' => $character->id()->value,
            'name' => $character->name(),
            'initials' => $character->initials(),
            'portraitUrl' => $path === null ? null : $this->portraits->url($path),
        ];
    }

    /**
     * @param  list<Category>  $categories  where a character of the person may be tied
     * @return array<string, mixed>
     */
    private function form(Authenticatable $user, array $categories): array
    {
        $names = [];
        foreach ($categories as $category) {
            $names[$category->id()->value] = $category->name();
        }

        return [
            'createUrl' => $this->rules->mayHaveCharacters($user) ? route('forum.characters.store') : null,
            'categories' => array_map(static fn (Category $category): array => [
                'id' => $category->id()->value,
                'name' => $category->name(),
                'parentId' => $category->parentId()?->value,
                'parentName' => ($parentId = $category->parentId()) instanceof CategoryId ? ($names[$parentId->value] ?? null) : null,
            ], $categories),
            'limits' => [
                'nameMax' => Character::MAX_NAME_LENGTH,
                'descriptionMax' => Character::MAX_DESCRIPTION_LENGTH,
                'portraitKilobytes' => SaveCharacterRequest::maxPortraitKilobytes($this->settings),
                'portraitTypes' => array_map(static fn (PostImageType $type): string => $type->mimeType(), $this->settings->imageTypes()),
                'perAccount' => CharacterService::MAX_PER_ACCOUNT,
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function actions(Character $character, bool $owner): array
    {
        $id = $character->id()->value;
        // Its owner brings back only what they retired; moderation, anything
        $restorable = $character->isRetired() && (! $owner || ! $character->isRetirementLocked());

        return [
            'updateUrl' => route('forum.characters.update', ['character' => $id]),
            'retireUrl' => $character->isRetired() && ($owner || $character->isRetirementLocked()) ? null : route('forum.characters.retire', ['character' => $id]),
            'restoreUrl' => $restorable ? route('forum.characters.restore', ['character' => $id]) : null,
            'deleteUrl' => route('forum.characters.destroy', ['character' => $id]),
        ];
    }
}
