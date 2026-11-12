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
use Modules\Forum\Http\Presenters\EngagementPresenter;
use Modules\Forum\Http\Presenters\ForumPresenter;

/**
 * /foro/c/<category>: its subcategories and the discussions of the category and of them, pinned
 * ones first. A category the reader may not read does not exist for them (404).
 */
final class CategoryController extends Controller
{
    use ListsDiscussions;

    public function __construct(
        private readonly ForumBrowser $browser,
        private readonly ForumPresenter $presenter,
        private readonly ForumUrlsInterface $urls,
        private readonly SeoMetadataFactoryInterface $seo,
        private readonly EngagementPresenter $engagement,
    ) {}

    public function show(Request $request, string $category): Response
    {
        $reader = $request->user();
        $readable = $this->browser->readableCategories($reader);
        $access = $this->browser->findReadable($readable, $category);
        abort_unless($access instanceof CategoryAccessDTO, 404);

        $scope = $this->browser->withSubcategories($readable, $access);
        $canStart = array_filter($scope, static fn (CategoryAccessDTO $candidate): bool => $candidate->canStartDiscussions()) !== [];

        return Inertia::render('Forum/Category', [
            'category' => [...$this->presenter->categoryHeader($access, $readable), 'follow' => $this->engagement->categoryFollow($reader, $access->category)],
            'subcategories' => $this->presenter->categoryTree($readable, $access->category->id()),
            ...$this->listProps($request, $scope, pinnedFirst: true, baseUrl: $this->urls->category($access->category)),
            'search' => $this->presenter->searchBox($readable, $access->category),
            'canStart' => $canStart,
            'newDiscussionUrl' => route('forum.discussions.create', $access->canStartDiscussions() ? ['categoria' => $access->category->slug()->value] : []),
            'markReadUrl' => $reader === null ? null : route('forum.categories.read', ['category' => $access->category->slug()->value]),
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: $access->category->name(),
                description: $access->category->description() ?? __('forum::messages.pages.category_description', ['name' => $access->category->name()]),
                noindex: ! $access->guestsRead,
            ))->toArray(),
        ]);
    }
}
