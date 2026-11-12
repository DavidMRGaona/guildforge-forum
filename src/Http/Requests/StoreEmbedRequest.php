<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Requests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Forum\Application\Services\ForumSuspensions;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * A link to read for the editor, from someone who may act in the forum (not suspended,
 * deactivated or anonymized), as writing a post asks: reading it makes the server fetch from
 * the service.
 */
final class StoreEmbedRequest extends FormRequest
{
    public function authorize(ForumSuspensions $suspensions): bool
    {
        $user = $this->user();

        return $user instanceof Authenticatable && ! $suspensions->isBarred($user);
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
        return ['url' => __('forum::messages.editor.embed_attribute')];
    }
}
