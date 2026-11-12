<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Presenters;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Services\ExternalImageRules;
use Modules\Forum\Domain\Enums\PostImageType;
use Modules\Forum\Http\Requests\StorePostImageRequest;

/**
 * What the post editor needs from the server: whether images may be uploaded (with the
 * permission), how many and in which formats, and whether images from other sites may be put
 * in (their own permission, and the sites of the settings).
 */
final readonly class EditorProps
{
    public function __construct(
        private AuthorizationServiceInterface $authorization,
        private ForumSettingsInterface $settings,
    ) {}

    /**
     * @return array{uploadUrl: string|null, maxImages: int, imageTypes: list<string>, externalImageUrl: string|null, externalImageHosts: list<string>}
     */
    public function for(?Authenticatable $user): array
    {
        $canUpload = $user instanceof Authenticatable && $this->authorization->can($user, StorePostImageRequest::PERMISSION);
        $hosts = $this->settings->externalImageHosts();
        $canTakeExternal = $hosts !== [] && $user instanceof Authenticatable && $this->authorization->can($user, ExternalImageRules::PERMISSION);

        return [
            'uploadUrl' => $canUpload ? route('forum.images.store') : null,
            'externalImageUrl' => $canTakeExternal ? route('forum.images.external') : null,
            'externalImageHosts' => $canTakeExternal ? $hosts : [],
            'maxImages' => $this->settings->imagesPerPost(),
            'imageTypes' => array_map(static fn (PostImageType $type): string => $type->mimeType(), $this->settings->imageTypes()),
        ];
    }
}
