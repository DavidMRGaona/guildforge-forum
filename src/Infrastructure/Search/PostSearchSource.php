<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Search;

use App\Application\Search\Contracts\IndexedSearchSourceInterface;
use App\Application\Search\DTOs\SearchDocumentDTO;
use Illuminate\Support\Str;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;

/**
 * The replies readers can find (the opening message belongs to its discussion's document), each at
 * its own address.
 */
final readonly class PostSearchSource implements IndexedSearchSourceInterface
{
    public const string TYPE = 'forum-post';

    public function __construct(
        private ForumSearchDocuments $documents,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('forum::messages.search.posts.label');
    }

    public function itemLabel(): string
    {
        return __('forum::messages.search.posts.item');
    }

    public function pastLabel(): ?string
    {
        return null;
    }

    public function document(string $id): ?SearchDocumentDTO
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $post = ForumSearchDocuments::replies()->with('discussion')->whereKey(strtolower($id))->first();

        return $post instanceof PostModel && $post->discussion !== null ? $this->documents->reply($post, $post->discussion) : null;
    }

    /**
     * @return iterable<SearchDocumentDTO>
     */
    public function documents(): iterable
    {
        foreach (ForumSearchDocuments::replies()->with('discussion')->lazyById(200) as $post) {
            $document = $post->discussion === null ? null : $this->documents->reply($post, $post->discussion);
            if ($document instanceof SearchDocumentDTO) {
                yield $document;
            }
        }
    }
}
