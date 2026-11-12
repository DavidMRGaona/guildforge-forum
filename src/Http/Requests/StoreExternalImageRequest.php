<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Requests;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Forum\Application\Services\ExternalImageRules;
use Modules\Forum\Application\Services\ForumSuspensions;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * The address of an image on another site, from someone with forum:posts.external_images who
 * may act in the forum (not suspended, deactivated or anonymized).
 * Whether its site is allowed and what it holds is checked when the server fetches it.
 */
final class StoreExternalImageRequest extends FormRequest
{
    public function authorize(AuthorizationServiceInterface $authorization, ForumSuspensions $suspensions): bool
    {
        $user = $this->user();

        // Copying makes the server fetch from the other site: not for whoever may not act in the forum
        return $user !== null && $authorization->can($user, ExternalImageRules::PERMISSION) && ! $suspensions->isBarred($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:'.RemoteUrl::MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['url' => __('forum::messages.editor.external_image_attribute')];
    }
}
