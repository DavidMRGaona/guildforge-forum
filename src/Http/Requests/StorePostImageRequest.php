<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Services\PostImageUploader;

/**
 * An image for a post: JPEG, PNG, WebP or GIF of 4 MB at most and no larger than 8000 px a
 * side before it is resized. The type is checked from the bytes, never from the name.
 */
final class StorePostImageRequest extends FormRequest
{
    /**
     * Only people who may write somewhere in the forum upload images for it.
     */
    public function authorize(ForumAccessInterface $access): bool
    {
        return $access->canWriteSomewhere($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'mimetypes:'.implode(',', array_keys(PostImageUploader::TYPES)),
                'max:'.PostImageUploader::MAX_KILOBYTES,
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
