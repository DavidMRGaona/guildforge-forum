<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Requests;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Services\PostImageUploader;
use Modules\Forum\Domain\Enums\PostImageType;

/**
 * An image for a post, from someone with forum:posts.upload_images: one of the formats and no
 * heavier than the forum settings allow, and no larger than 8000 px a side before it is
 * resized. The type is checked again from the bytes when it is stored.
 */
final class StorePostImageRequest extends FormRequest
{
    public const string PERMISSION = 'forum:posts.upload_images';

    public function authorize(AuthorizationServiceInterface $authorization): bool
    {
        $user = $this->user();

        return $user !== null && $authorization->can($user, self::PERMISSION);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $settings = app(ForumSettingsInterface::class);
        $types = array_map(static fn (PostImageType $type): string => $type->mimeType(), $settings->imageTypes());

        return [
            'image' => [
                'required',
                'file',
                // No format allowed: nothing passes
                $types === [] ? Rule::in([]) : 'mimetypes:'.implode(',', $types),
                'max:'.$settings->imageMaxKilobytes(),
                Rule::dimensions()->maxWidth(PostImageUploader::MAX_SOURCE_DIMENSION)->maxHeight(PostImageUploader::MAX_SOURCE_DIMENSION),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['image' => __('forum::messages.editor.image_attribute')];
    }
}
