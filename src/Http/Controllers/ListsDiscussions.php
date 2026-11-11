<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\Queries\DiscussionCursor;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;
use Modules\Forum\Http\Presenters\ForumPresenter;

/**
 * The discussion list of a page with its filters (`?sin-leer=1`, `?siguiendo=1`, `?etiqueta=<key>`) and the
 * cursor of the next page (`?desde=<cursor>`). A filter value that is not one of the offered
 * ones is ignored, never passed on.
 *
 * @property-read ForumBrowser $browser
 * @property-read ForumPresenter $presenter
 */
trait ListsDiscussions
{
    /**
     * @param  list<CategoryAccessDTO>  $scope
     * @return array<string, mixed>
     */
    private function listProps(Request $request, array $scope, bool $pinnedFirst, string $baseUrl): array
    {
        $reader = $request->user();
        $topics = $this->browser->topics($scope);

        $topicKey = $request->query('etiqueta');
        $topicKey = is_string($topicKey) && in_array($topicKey, array_map(static fn (DiscussionTopic $topic): string => $topic->key, $topics), true) ? $topicKey : null;
        $onlyUnread = $reader !== null && $request->query('sin-leer') === '1';
        $onlyFollowed = $reader !== null && $request->query('siguiendo') === '1';
        $cursorValue = $request->query('desde');
        $cursor = DiscussionCursor::decode(is_string($cursorValue) ? $cursorValue : null);

        $page = $this->browser->discussions($reader, $scope, $topicKey, $onlyUnread, $cursor, $pinnedFirst, $onlyFollowed);

        $filters = array_filter(['etiqueta' => $topicKey, 'sin-leer' => $onlyUnread ? '1' : null, 'siguiendo' => $onlyFollowed ? '1' : null]);
        $query = static fn (array $extra): string => ($all = http_build_query([...$filters, ...$extra])) === '' ? $baseUrl : "{$baseUrl}?{$all}";

        return [
            'discussions' => [
                ...$this->presenter->discussionList($page, $scope),
                'nextUrl' => $page->next instanceof DiscussionCursor ? $query(['desde' => $page->next->encode()]) : null,
                'firstUrl' => $cursor instanceof DiscussionCursor ? $query([]) : null,
            ],
            'filters' => [
                'topic' => $topicKey,
                'unread' => $onlyUnread,
                'following' => $onlyFollowed,
                'topics' => $this->presenter->topics($topics),
                'canFilterUnread' => $reader !== null,
                'baseUrl' => $baseUrl,
            ],
        ];
    }
}
