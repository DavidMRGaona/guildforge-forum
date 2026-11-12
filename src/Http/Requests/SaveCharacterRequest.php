<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\DTOs\CharacterInputDTO;
use Modules\Forum\Application\Services\PostImageUploader;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Enums\PostImageType;

/**
 * A character as the form sends it (multipart): name, description, the category to tie it to
 * (none for any) and, optionally, a new portrait in one of the forum's image formats, or the
 * order to drop the one it has. Whoever may do it is decided by the service.
 */
final class SaveCharacterRequest extends FormRequest
{
    /** Portraits are shown small: never heavier than this, whatever the image settings say */
    public const int MAX_PORTRAIT_KILOBYTES = 2048;

    public static function maxPortraitKilobytes(ForumSettingsInterface $settings): int
    {
        return min(self::MAX_PORTRAIT_KILOBYTES, $settings->imageMaxKilobytes());
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $settings = app(ForumSettingsInterface::class);
        $types = array_map(static fn (PostImageType $type): string => $type->mimeType(), $settings->imageTypes());

        return [
            'name' => ['required', 'string', 'max:'.Character::MAX_NAME_LENGTH],
            'description' => ['nullable', 'string', 'max:'.Character::MAX_DESCRIPTION_LENGTH],
            'category' => ['nullable', 'string', 'uuid'],
            'portrait' => [
                'nullable',
                'file',
                $types === [] ? Rule::in([]) : 'mimetypes:'.implode(',', $types),
                'max:'.self::maxPortraitKilobytes($settings),
                Rule::dimensions()->maxWidth(PostImageUploader::MAX_SOURCE_DIMENSION)->maxHeight(PostImageUploader::MAX_SOURCE_DIMENSION),
            ],
            'remove_portrait' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('forum::messages.characters.fields.name'),
            'description' => __('forum::messages.characters.fields.description'),
            'category' => __('forum::messages.characters.fields.category'),
            'portrait' => __('forum::messages.characters.fields.portrait'),
        ];
    }

    public function characterInput(): CharacterInputDTO
    {
        $portrait = $this->file('portrait');
        $contents = $portrait === null || is_array($portrait) ? false : file_get_contents($portrait->getRealPath());
        $description = $this->validated('description');
        $category = $this->validated('category');

        return new CharacterInputDTO(
            name: (string) $this->validated('name'),
            description: is_string($description) ? $description : null,
            categoryId: is_string($category) ? $category : null,
            portrait: is_string($contents) ? $contents : null,
            removePortrait: $this->boolean('remove_portrait'),
        );
    }
}
