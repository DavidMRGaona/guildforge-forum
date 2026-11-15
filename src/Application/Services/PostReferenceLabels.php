<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Modules\Forum\Application\Content\Nodes\PostReferenceNode;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Labels the references of documents about to be edited with the number each post has now
 * («#?» once it is gone or has none), so the editor shows them as they read. Only whoever may
 * edit a post gets its document, and the number tells nothing its text did not: the label is
 * dropped again when the post is saved.
 */
final readonly class PostReferenceLabels
{
    public function __construct(
        private PostRepositoryInterface $posts,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $documents  by post id
     * @return array<string, array<string, mixed>>
     */
    public function fillMany(array $documents): array
    {
        $ids = [];
        foreach ($documents as $document) {
            self::collect($document, $ids);
        }
        if ($ids === []) {
            return $documents;
        }

        $numbers = [];
        foreach ($this->posts->findMany(array_map(PostId::fromString(...), array_map(strval(...), array_keys($ids)))) as $post) {
            $numbers[$post->id()->value] = $post->number()?->value;
        }

        return array_map(static fn (array $document): array => self::label($document, $numbers), $documents);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, true>  $ids
     */
    private static function collect(array $node, array &$ids): void
    {
        if (($node['type'] ?? null) === (new PostReferenceNode)->name()) {
            $id = AccountId::normalize(is_array($node['attrs'] ?? null) && is_string($node['attrs']['postId'] ?? null) ? $node['attrs']['postId'] : '');
            if ($id !== null) {
                $ids[$id] = true;
            }

            return;
        }

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                self::collect($child, $ids);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, int|null>  $numbers
     * @return array<array-key, mixed>
     */
    private static function label(array $node, array $numbers): array
    {
        if (($node['type'] ?? null) === (new PostReferenceNode)->name()) {
            $attributes = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            $id = AccountId::normalize(is_string($attributes['postId'] ?? null) ? $attributes['postId'] : '') ?? '';

            return [...$node, 'attrs' => [...$attributes, 'label' => '#'.($numbers[$id] ?? '?')]];
        }
        if (! is_array($node['content'] ?? null)) {
            return $node;
        }

        return [...$node, 'content' => array_map(static fn (mixed $child): mixed => is_array($child) ? self::label($child, $numbers) : $child, $node['content'])];
    }
}
