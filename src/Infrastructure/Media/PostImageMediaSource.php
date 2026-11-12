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
use Modules\Forum\Application\Content\Nodes\EmbedNode;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Domain\Repositories\EmbedCardRepositoryInterface;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostRevisionModel;

/**
 * The images of forum posts: uploads (forum/posts/) and the forum's copies of images from other
 * sites and of the thumbnails of embedded posts (forum/external/). A file is in use while any
 * post shows it (an embedded post shows its card's thumbnail) in its
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

    /** The same for documents with an embedded post, whose card may hold a thumbnail */
    private const string EMBED_MARKER = '"embed"';

    public function __construct(
        private DocumentRenderer $renderer,
        private MediaFileIdResolverInterface $resolver,
        private EmbedCardRepositoryInterface $cards,
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
        return [ImageNode::FOLDER, ImageNode::EXTERNAL_FOLDER];
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
        foreach ($this->postsMentioning(self::IMAGE_MARKER, self::EMBED_MARKER)->lazyById(self::CHUNK_SIZE) as $post) {
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

        // A thumbnail is named by a card, not by the documents: the posts embedding its post
        foreach ($this->cards->withThumbnails() as $card) {
            if ($card->thumbnailPath === null || $this->resolver->fromStoredPath($card->thumbnailPath)?->equals($file) !== true) {
                continue;
            }
            $piece = StoredMediaIdPrefilter::longestPlainRun($card->embedId);
            foreach ($this->postsMentioning($piece === '' ? self::EMBED_MARKER : $piece)->lazyById(self::CHUNK_SIZE) as $post) {
                if (in_array($card->thumbnailPath, $this->pathsOf($post), true)) {
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
    private function postsMentioning(string ...$texts): Builder
    {
        // json columns are compared as text, the same in PostgreSQL and SQLite
        $like = "CAST(body AS TEXT) LIKE ? ESCAPE '\\'";
        $patterns = array_map(static fn (string $text): string => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text).'%', $texts);

        return PostModel::query()->where(static function (Builder $query) use ($like, $patterns): void {
            foreach ($patterns as $pattern) {
                $query->orWhereRaw($like, [$pattern])
                    ->orWhereIn('id', PostRevisionModel::query()->select('post_id')->whereRaw($like, [$pattern]));
            }
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
        $embed = (new EmbedNode)->name();

        $paths = [];
        $embeds = [];
        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }
            $found = $this->renderer->attributesOf($document, [$image, $embed]);
            foreach ($found[$image] ?? [] as $attributes) {
                /** @var array<string, mixed> $attributes */
                $path = ImageNode::storedPath($attributes);
                if ($path !== null) {
                    $paths[$path] = true;
                }
            }
            foreach ($found[$embed] ?? [] as $attributes) {
                if (is_string($attributes['service'] ?? null) && is_string($attributes['id'] ?? null)) {
                    $embeds[$attributes['service'].' '.$attributes['id']] = [$attributes['service'], $attributes['id']];
                }
            }
        }

        foreach ($this->cards->findMany(array_values($embeds)) as $card) {
            if ($card->thumbnailPath !== null && str_starts_with($card->thumbnailPath, ImageNode::EXTERNAL_FOLDER)) {
                $paths[$card->thumbnailPath] = true;
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
