<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\DTOs\RedirectTargetDTO;
use Modules\Forum\Application\DTOs\StartDiscussionDTO;
use Modules\Forum\Application\Services\ConversationReader;
use Modules\Forum\Application\Services\DiscussionStarter;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumRedirects;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\ModerationLogEntry;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\DiscussionNotStartedException;
use Modules\Forum\Domain\Exceptions\InvalidDiscussionException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionSlug;
use Modules\Forum\Http\Presenters\CharacterPresenter;
use Modules\Forum\Http\Presenters\EditorProps;
use Modules\Forum\Http\Presenters\EngagementPresenter;
use Modules\Forum\Http\Presenters\ForumPresenter;
use Modules\Forum\Http\Presenters\ModerationProps;
use Modules\Forum\Http\Requests\StoreDiscussionRequest;

/**
 * Reading a discussion (/foro/t/<number>-<slug>[/<post number>]) and starting one. The
 * discussion is found by its number alone: any other slug, or a post number that is not where
 * the page lands, redirects to the right address.
 */
final class DiscussionController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly DiscussionRepositoryInterface $discussions,
        private readonly ConversationReader $reader,
        private readonly ForumBrowser $browser,
        private readonly DiscussionStarter $starter,
        private readonly ForumPresenter $presenter,
        private readonly EditorProps $editor,
        private readonly ForumUrlsInterface $urls,
        private readonly SeoMetadataFactoryInterface $seo,
        private readonly EngagementPresenter $engagement,
        private readonly ForumModeration $moderation,
        private readonly CategoryRepositoryInterface $categories,
        private readonly SuspensionService $suspensionService,
        private readonly ModerationProps $moderationProps,
        private readonly CharacterPresenter $characters,
        private readonly ForumRedirects $redirects,
    ) {}

    public function show(Request $request, string $discussion, ?string $post = null): Response|RedirectResponse
    {
        $user = $request->user();
        $number = (int) Str::before($discussion, '-');
        $found = $this->discussions->findByNumber($number);

        // Moderation moved the post (split off) or the whole discussion (merged): its new place
        if (! $found instanceof Discussion || $post !== null) {
            $moved = $this->redirects->resolve($user, $number, $post === null ? null : (int) $post);
            if ($moved instanceof RedirectTargetDTO) {
                return redirect()->to($this->urls->discussion($moved->discussion, $moved->postNumber), 301);
            }
        }
        abort_unless($found instanceof Discussion, 404);

        try {
            $page = $this->reader->open($user, $found->id(), $post === null ? null : (int) $post);
        } catch (DiscussionNotFoundException) {
            abort(404);
        }

        // One address per post: the right slug, and no "/1" (the first post opens the page)
        $asked = $post === null ? null : (int) $post;
        $landed = $asked === null ? null : $page->focusNumber;
        if ($discussion !== DiscussionSlug::segment((int) $found->number(), $found->title()->value) || ($asked !== null && ($landed !== $asked || $asked === 1))) {
            return redirect()->to($this->urls->discussion($found, $landed), 301);
        }

        $readable = $this->browser->readableCategories($user);
        $first = $page->posts[0] ?? null;
        $seo = $this->seo->forPage(new SeoPageDTO(
            title: $found->title()->value,
            description: $first instanceof Post && $first->number()?->value === 1 && $first->isVisible() ? Str::limit($first->body()->plainText, 300) : null,
            noindex: ! $page->guestsRead,
        ))->toArray();
        // Each page of posts is one address, whichever of its posts was asked for
        $seo['canonicalUrl'] = $this->urls->discussion($found, $page->pageStart, absolute: true);

        $conversation = $this->engagement->conversation($this->presenter->conversation($page, $readable), $page, $user);
        $conversation['posts'] = $this->moderationProps->withReportLinks($conversation['posts'], $user);

        return Inertia::render('Forum/Discussion', [
            ...$conversation,
            'reply' => [
                'canPost' => $page->canPost,
                'postUrl' => route('forum.posts.store', ['discussion' => $found->id()->value]),
                'mentionsUrl' => $user === null ? null : route('forum.mentions.index', ['discussion' => $found->id()->value]),
                ...$this->editor->for($user),
                'suspension' => $this->moderationProps->suspensionNotice($user),
                // «Hablar como»: the characters the reader may write as here
                'characters' => $page->canPost ? $this->characters->picker($user, $page->category, $found->id()) : null,
            ],
            'readUrl' => $user === null ? null : route('forum.discussions.read', ['discussion' => $found->id()->value]),
            'moderation' => $page->canModerate && $user !== null ? $this->moderationMenu($user, $page->discussion) : null,
            'seo' => $seo,
        ]);
    }

    /**
     * What the discussion's moderation menu offers this moderator.
     *
     * @return array<string, mixed>
     */
    private function moderationMenu(Authenticatable $moderator, Discussion $discussion): array
    {
        $targets = $this->moderation->moveTargets($moderator, $discussion);
        $restructures = $this->moderation->canRestructure($moderator, $discussion);
        $names = [];
        foreach ($this->categories->all() as $category) {
            $names[$category->id()->value] = $category->name();
        }

        return [
            'queueUrl' => route('forum.moderation.index'),
            'suspendUrl' => $this->suspensionService->canSuspend($moderator) ? route('forum.suspensions.store') : null,
            'actionsUrl' => route('forum.moderation.discussions', ['discussion' => $discussion->id()->value]),
            'splitUrl' => $restructures ? route('forum.moderation.discussions.split', ['discussion' => $discussion->id()->value]) : null,
            'mergeUrl' => $restructures ? route('forum.moderation.discussions.merge', ['discussion' => $discussion->id()->value]) : null,
            'mergeTargetUrl' => $restructures ? route('forum.moderation.discussions.merge-target', ['discussion' => $discussion->id()->value]) : null,
            'splitCategories' => array_map(static fn (Category $category): array => [
                'id' => $category->id()->value,
                'name' => $category->name(),
                'parentName' => ($parentId = $category->parentId()) instanceof CategoryId ? ($names[$parentId->value] ?? null) : null,
            ], $restructures ? $this->moderation->splitCategories($moderator, $discussion) : []),
            'deleteUrl' => $this->moderation->canDelete($moderator, $discussion)
                ? route('forum.moderation.discussions.destroy', ['discussion' => $discussion->id()->value])
                : null,
            'moveTargets' => array_map(static fn (Category $category): array => [
                'id' => $category->id()->value,
                'name' => $category->name(),
                'parentName' => ($parentId = $category->parentId()) instanceof CategoryId ? ($names[$parentId->value] ?? null) : null,
            ], $targets),
            'limits' => [
                'titleMin' => DiscussionStarter::MIN_TITLE_LENGTH,
                'titleMax' => DiscussionStarter::MAX_TITLE_LENGTH,
                'reasonMax' => ModerationLogEntry::MAX_REASON_LENGTH,
                'hiddenReasonMax' => Post::MAX_HIDDEN_REASON_LENGTH,
                'splitMax' => ForumModeration::MAX_SPLIT,
            ],
        ];
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        $categories = $this->starter->categoriesFor($user);
        $readable = $this->browser->readableCategories($user);

        $wanted = $request->query('categoria');
        $selected = null;
        foreach ($categories as $access) {
            if ($access->category->slug()->value === $wanted) {
                $selected = $access->category->id()->value;
            }
        }

        return Inertia::render('Forum/Create', [
            'categories' => array_map(static fn (CategoryAccessDTO $access): array => [
                'id' => $access->category->id()->value,
                'name' => $access->category->name(),
                'parentName' => ($parentId = $access->category->parentId()) instanceof CategoryId ? ForumBrowser::categoryById($readable, $parentId->value)?->name() : null,
                'canModerate' => $access->canModerate,
            ], $categories),
            'topics' => $this->presenter->topics($this->starter->topicsFor($user)),
            'selectedCategoryId' => $selected,
            'storeUrl' => route('forum.discussions.store'),
            'cancelUrl' => $this->urls->index(),
            'editor' => $this->editor->for($user),
            'characters' => $this->characters->pickerForCategories($user, array_map(static fn (CategoryAccessDTO $access): Category => $access->category, $categories)),
            'suspension' => $this->moderationProps->suspensionNotice($user),
            'limits' => ['titleMin' => DiscussionStarter::MIN_TITLE_LENGTH, 'titleMax' => DiscussionStarter::MAX_TITLE_LENGTH, 'topicsMax' => DiscussionStarter::MAX_TOPICS],
            'seo' => $this->seo->forPage(new SeoPageDTO(title: __('forum::messages.pages.create_title'), noindex: true))->toArray(),
        ]);
    }

    public function store(StoreDiscussionRequest $request): JsonResponse
    {
        return $this->respond(function () use ($request): JsonResponse {
            $user = $request->user();
            abort_unless($user !== null, 401);

            try {
                $discussion = $this->starter->start($user, new StartDiscussionDTO(
                    categoryId: (string) $request->validated('category'),
                    title: (string) $request->validated('title'),
                    document: $this->documentFrom($request),
                    topicKeys: $request->topicKeys(),
                    pinned: $request->boolean('pinned'),
                    closed: $request->boolean('closed'),
                    characterId: $this->speakerFrom($request)[1],
                ));
            } catch (DiscussionNotStartedException $exception) {
                $message = __("forum::messages.discussions.errors.{$exception->reason}");

                return response()->json(['message' => $message, 'errors' => [$exception->field => [$message]]], 422);
            } catch (InvalidDiscussionException) {
                $message = __('forum::messages.discussions.errors.title', ['min' => DiscussionStarter::MIN_TITLE_LENGTH, 'max' => DiscussionStarter::MAX_TITLE_LENGTH]);

                return response()->json(['message' => $message, 'errors' => ['title' => [$message]]], 422);
            }

            return response()->json(['url' => $this->urls->discussion($discussion)], 201);
        });
    }
}
