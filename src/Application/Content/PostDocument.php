<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content;

use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Content\Nodes\MentionNode;
use Modules\Forum\Application\Content\Nodes\PostQuoteNode;

/**
 * A post document that passed the schema, exactly as it came, with the attributes of its nodes
 * collected on the way so callers need not walk it again. Built only by DocumentSchema.
 */
final readonly class PostDocument
{
    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, list<array<string, mixed>>>  $attributes  of every node, by node type, in document order
     */
    public function __construct(
        private array $document,
        private array $attributes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->document;
    }

    /**
     * The attributes of every node of a type, in document order (a node without attributes
     * gives an empty array).
     *
     * @return list<array<string, mixed>>
     */
    public function attributesOf(string $type): array
    {
        return $this->attributes[$type] ?? [];
    }

    /**
     * @return list<string>
     */
    public function mentionedAccountIds(): array
    {
        return $this->distinct((new MentionNode)->name(), 'id');
    }

    /**
     * Stored paths of the images, as uploaded.
     *
     * @return list<string>
     */
    public function imagePaths(): array
    {
        return $this->distinct((new ImageNode)->name(), 'src');
    }

    /**
     * @return list<string>
     */
    public function quotedPostIds(): array
    {
        return $this->distinct((new PostQuoteNode)->name(), 'postId');
    }

    /**
     * @return list<string>
     */
    private function distinct(string $type, string $key): array
    {
        $values = [];
        foreach ($this->attributesOf($type) as $attributes) {
            $value = $attributes[$key] ?? null;
            if (is_string($value)) {
                $values[$value] = true;
            }
        }

        return array_map(strval(...), array_keys($values));
    }
}
