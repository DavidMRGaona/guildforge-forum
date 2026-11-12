<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Application\Services\ModerationQueue;
use Modules\Forum\Http\Presenters\ForumPresenter;

/**
 * /foro: the categories the reader may read and the latest discussions across them.
 */
final class ForumIndexController extends Controller
{
    use ListsDiscussions;

    public function __construct(
        private readonly ForumBrowser $browser,
        private readonly ForumPresenter $presenter,
        private readonly ForumUrlsInterface $urls,
        private readonly SeoMetadataFactoryInterface $seo,
        private readonly ModerationQueue $queue,
    ) {}

    public function __invoke(Request $request): Response
    {
        $reader = $request->user();
        $readable = $this->browser->readableCategories($reader);

        return Inertia::render('Forum/Index', [
            'categories' => $this->presenter->categoryTree($readable),
            ...$this->listProps($request, $readable, pinnedFirst: false, baseUrl: $this->urls->index()),
            'search' => $this->presenter->searchBox($readable),
            'canStart' => array_filter($readable, static fn (CategoryAccessDTO $access): bool => $access->canStartDiscussions()) !== [],
            'newDiscussionUrl' => route('forum.discussions.create'),
            'markReadUrl' => $reader === null ? null : route('forum.read'),
            'moderationUrl' => $this->queue->hasQueue($reader) ? route('forum.moderation.index') : null,
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: __('forum::messages.pages.index_title'),
                description: __('forum::messages.pages.index_description'),
            ))->toArray(),
        ]);
    }
}
