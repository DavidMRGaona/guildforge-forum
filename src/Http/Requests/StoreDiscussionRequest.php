<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Forum\Application\Services\DiscussionStarter;

/**
 * The fields of a new discussion besides its first post, whose document is read from the raw
 * body (the site trims strings in the parsed input). Which categories and tags are allowed is
 * decided by DiscussionStarter.
 */
final class StoreDiscussionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:'.DiscussionStarter::MIN_TITLE_LENGTH, 'max:'.DiscussionStarter::MAX_TITLE_LENGTH],
            'category' => ['required', 'uuid'],
            'topics' => ['sometimes', 'array', 'max:'.DiscussionStarter::MAX_TOPICS],
            'topics.*' => ['string', 'max:64'],
            'pinned' => ['sometimes', 'boolean'],
            'closed' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => __('forum::messages.discussions.fields.title'),
            'category' => __('forum::messages.discussions.fields.category'),
            'topics' => __('forum::messages.discussions.fields.topics'),
        ];
    }

    /**
     * @return list<string>
     */
    public function topicKeys(): array
    {
        $topics = $this->validated('topics', []);

        return is_array($topics) ? array_values(array_filter($topics, is_string(...))) : [];
    }
}
