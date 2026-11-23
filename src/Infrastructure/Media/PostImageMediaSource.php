<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Media;

use App\Application\Media\Contracts\MediaUsageSourceInterface;
use App\Application\Media\DTOs\MediaUsageDTO;
use App\Application\Media\Services\MediaFileIdResolverInterface;
use App\Domain\Media\ValueObjects\MediaFileId;
use App\Infrastructure\Media\Sources\StoredMediaIdPrefilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
 *
 * Only what every member reads may be seen in the media library (forum#155, PublicForumImages): an
 * image in the current body of a post every member reads. The rest (hidden, pending or deleted
 * posts, hidden discussions or ones awaiting approval, categories for association members, a role
 * or a roster, the whole forum while it is a preview, and earlier versions, which only moderators
 * read) is marked hidden, under a title that names neither the discussion nor the post. Private
 * messages hold no images (ConversationDocuments), so there is nothing else to hide.
 *
 * It does not declare ShowsMediaPreviews yet, so the library still conceals every file of its
 * folders: the core shows the unused files of a source with the marker (those of a deleted
 * discussion in a private category, of a purged post, uploads never posted), and the forum cannot
 * hide those until a source may ask for it (core#843).
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
        private PublicForumImages $public,
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
        // Read once per walk; what cannot be read fails the walk, never shows anything
        $categoryIds = $this->public->categoryIds();

        // Who reads them is asked once per chunk, not once per post
        foreach ($this->postsMentioning(self::IMAGE_MARKER, self::EMBED_MARKER)->lazyById(self::CHUNK_SIZE)->chunk(self::CHUNK_SIZE) as $chunk) {
            /** @var list<PostModel> $posts */
            $posts = $chunk->values()->all();
            $read = $this->public->readPostIds(array_map(static fn (PostModel $post): string => $post->id, $posts), $categoryIds);
            foreach ($posts as $post) {
                yield from $this->usagesOf($post, isset($read[strtolower($post->id)]));
            }
        }
    }

    public function usagesFor(string $recordId): array
    {
        if (! Str::isUuid($recordId)) {
            return [];
        }

        $post = PostModel::query()->find($recordId);

        if (! $post instanceof PostModel) {
            return [];
        }

        $read = $this->public->readPostIds([$post->id], $this->public->categoryIds());

        return $this->usagesOf($post, isset($read[strtolower($post->id)]));
    }

    public function usesFile(MediaFileId $file): bool
    {
        $piece = StoredMediaIdPrefilter::longestPlainRun($file->value);

        foreach ($this->postsMentioning($piece === '' ? self::IMAGE_MARKER : $piece)->lazyById(self::CHUNK_SIZE) as $post) {
            foreach (array_keys($this->pathsOf($post)) as $path) {
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
                if (array_key_exists($card->thumbnailPath, $this->pathsOf($post))) {
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
     * Whether the post's body, or an earlier version of it, may hold an image: only then can a
     * change of who reads it change anything in the media library. A prefilter, never wrong the
     * other way (a post without the markers holds no image).
     */
    public function mayHoldImages(string $postId): bool
    {
        if (! Str::isUuid($postId)) {
            return false;
        }

        // The post first, then its own versions only (a correlated EXISTS on post_id), never a
        // scan of every version in the forum
        $post = "CAST(forum_posts.body AS TEXT) LIKE ? ESCAPE '\\'";
        $revision = "CAST(forum_post_revisions.body AS TEXT) LIKE ? ESCAPE '\\'";
        $patterns = array_map($this->pattern(...), [self::IMAGE_MARKER, self::EMBED_MARKER]);

        return PostModel::query()
            ->whereKey(strtolower($postId))
            ->where(static function (Builder $query) use ($post, $revision, $patterns): void {
                foreach ($patterns as $pattern) {
                    $query->orWhereRaw($post, [$pattern]);
                }
                $query->orWhereExists(static function (QueryBuilder $revisions) use ($revision, $patterns): void {
                    $revisions->selectRaw('1')
                        ->from('forum_post_revisions')
                        ->whereColumn('forum_post_revisions.post_id', 'forum_posts.id')
                        ->where(static function (QueryBuilder $mentions) use ($revision, $patterns): void {
                            foreach ($patterns as $pattern) {
                                $mentions->orWhereRaw($revision, [$pattern]);
                            }
                        });
                });
            })
            ->exists();
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
        $patterns = array_map($this->pattern(...), $texts);

        return PostModel::query()->where(static function (Builder $query) use ($like, $patterns): void {
            foreach ($patterns as $pattern) {
                $query->orWhereRaw($like, [$pattern])
                    ->orWhereIn('id', PostRevisionModel::query()->select('post_id')->whereRaw($like, [$pattern]));
            }
        });
    }

    /**
     * A LIKE pattern for the text, its wildcards escaped.
     */
    private function pattern(string $text): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text).'%';
    }

    /**
     * @param  bool  $read  whether every member reads the post (PublicForumImages)
     * @return list<MediaUsageDTO>
     */
    private function usagesOf(PostModel $post, bool $read): array
    {
        $paths = $this->pathsOf($post);
        if ($paths === []) {
            return [];
        }

        // A post every member reads sits in a discussion that exists: only then is it named
        $discussion = $read ? DiscussionModel::query()->find($post->discussion_id) : null;
        $read = $discussion instanceof DiscussionModel;
        $title = $discussion instanceof DiscussionModel
            ? __('forum::messages.media.post_title', ['discussion' => $discussion->title, 'number' => $post->number])
            : __('forum::messages.media.private_post_title');
        $role = __('forum::messages.media.role');

        $usages = [];
        foreach ($paths as $path => $inCurrentBody) {
            // What only an earlier version shows is read by moderators alone
            $usages[] = new MediaUsageDTO($path, $post->id, $role, $title, hidden: ! $read || ! $inCurrentBody);
        }

        return $usages;
    }

    /**
     * Image paths of the post and its earlier versions, each once, telling whether the current
     * body shows it (the path of a card's thumbnail counts as shown when the current body embeds it).
     *
     * @return array<string, bool>
     */
    private function pathsOf(PostModel $post): array
    {
        $documents = [$post->body, ...PostRevisionModel::query()->where('post_id', $post->id)->pluck('body')->all()];
        $image = (new ImageNode)->name();
        $embed = (new EmbedNode)->name();

        $paths = [];
        $embeds = [];
        foreach ($documents as $index => $document) {
            if (! is_array($document)) {
                continue;
            }
            $current = $index === 0;
            $found = $this->renderer->attributesOf($document, [$image, $embed]);
            foreach ($found[$image] ?? [] as $attributes) {
                /** @var array<string, mixed> $attributes */
                $path = ImageNode::storedPath($attributes);
                if ($path !== null) {
                    $paths[$path] = ($paths[$path] ?? false) || $current;
                }
            }
            foreach ($found[$embed] ?? [] as $attributes) {
                if (is_string($attributes['service'] ?? null) && is_string($attributes['id'] ?? null)) {
                    $key = $attributes['service'].' '.$attributes['id'];
                    $embeds[$key] = [$attributes['service'], $attributes['id'], ($embeds[$key][2] ?? false) || $current];
                }
            }
        }

        $shownEmbeds = [];
        foreach ($embeds as [$service, $id, $current]) {
            $shownEmbeds[$service.' '.$id] = $current;
        }

        $cards = $this->cards->findMany(array_values(array_map(static fn (array $embed): array => [$embed[0], $embed[1]], $embeds)));
        foreach ($cards as $card) {
            if ($card->thumbnailPath !== null && str_starts_with($card->thumbnailPath, ImageNode::EXTERNAL_FOLDER)) {
                $current = $shownEmbeds[$card->service.' '.$card->embedId] ?? false;
                $paths[$card->thumbnailPath] = ($paths[$card->thumbnailPath] ?? false) || $current;
            }
        }

        return $paths;
    }
}
