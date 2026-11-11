<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Media;

use App\Application\Media\Contracts\MediaUsageSourceInterface;
use App\Application\Media\DTOs\MediaUsageDTO;
use App\Application\Media\Services\MediaFileIdResolverInterface;
use App\Domain\Media\ValueObjects\MediaFileId;
use App\Infrastructure\Media\Sources\StoredMediaIdPrefilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostRevisionModel;

/**
 * The images of forum posts (forum/posts/). A file is in use while any post shows it in its
 * current body or in one of its earlier versions, whatever the post's status: hidden, pending
 * and deleted posts keep their body for moderators. Posts have no panel page, so no edit link.
 */
final readonly class PostImageMediaSource implements MediaUsageSourceInterface
{
    public const string TYPE = 'forum.posts';

    private const int CHUNK_SIZE = 200;

    /**
     * What every document with an image holds, whatever the JSON spacing: the node's type as a
     * string. Not the folder, since stored JSON escapes its slashes.
     */
    private const string IMAGE_MARKER = '"image"';

    public function __construct(
        private DocumentRenderer $renderer,
        private MediaFileIdResolverInterface $resolver,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('forum::messages.media.source');
    }

    public function folders(): array
    {
        return [ImageNode::FOLDER];
    }

    public function excludedSubfolders(): array
    {
        return [];
    }

    public function hidden(): bool
    {
        return false;
    }

    public function usages(): iterable
    {
        foreach ($this->postsMentioning(self::IMAGE_MARKER)->lazyById(self::CHUNK_SIZE) as $post) {
            yield from $this->usagesOf($post);
        }
    }

    public function usagesFor(string $recordId): array
    {
        if (! Str::isUuid($recordId)) {
            return [];
        }

        $post = PostModel::query()->find($recordId);

        return $post instanceof PostModel ? $this->usagesOf($post) : [];
    }

    public function usesFile(MediaFileId $file): bool
    {
        $piece = StoredMediaIdPrefilter::longestPlainRun($file->value);

        foreach ($this->postsMentioning($piece === '' ? self::IMAGE_MARKER : $piece)->lazyById(self::CHUNK_SIZE) as $post) {
            foreach ($this->pathsOf($post) as $path) {
                if ($this->resolver->fromStoredPath($path)?->equals($file) === true) {
                    return true;
                }
            }
        }

        return false;
    }

    public function editUrl(string $recordId, object $user): ?string
    {
        return null;
    }

    /**
     * Posts whose body, or an earlier version of it, holds the text. A prefilter: the paths are
     * confirmed by reading the documents.
     *
     * @return Builder<PostModel>
     */
    private function postsMentioning(string $text): Builder
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text).'%';
        // json columns are compared as text, the same in PostgreSQL and SQLite
        $like = "CAST(body AS TEXT) LIKE ? ESCAPE '\\'";

        return PostModel::query()->where(static function (Builder $query) use ($like, $pattern): void {
            $query->whereRaw($like, [$pattern])
                ->orWhereIn('id', PostRevisionModel::query()->select('post_id')->whereRaw($like, [$pattern]));
        });
    }

    /**
     * @return list<MediaUsageDTO>
     */
    private function usagesOf(PostModel $post): array
    {
        $title = $this->titleOf($post);

        return array_map(
            static fn (string $path): MediaUsageDTO => new MediaUsageDTO($path, $post->id, __('forum::messages.media.role'), $title),
            $this->pathsOf($post),
        );
    }

    /**
     * Image paths of the post and its earlier versions, each once.
     *
     * @return list<string>
     */
    private function pathsOf(PostModel $post): array
    {
        $documents = [$post->body, ...PostRevisionModel::query()->where('post_id', $post->id)->pluck('body')->all()];
        $image = (new ImageNode)->name();

        $paths = [];
        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }
            foreach ($this->renderer->attributesOf($document, [$image])[$image] ?? [] as $attributes) {
                $src = $attributes['src'] ?? null;
                if (is_string($src) && str_starts_with($src, ImageNode::FOLDER)) {
                    $paths[$src] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($paths));
    }

    private function titleOf(PostModel $post): string
    {
        $discussion = DiscussionModel::query()->find($post->discussion_id);
        $title = $discussion instanceof DiscussionModel ? $discussion->title : '';

        return $post->number === null
            ? __('forum::messages.media.pending_post_title', ['discussion' => $title])
            : __('forum::messages.media.post_title', ['discussion' => $title, 'number' => $post->number]);
    }
}
