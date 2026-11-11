<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Contracts;

use Modules\Forum\Application\Content\NodeGroup;
use Modules\Forum\Application\Content\NodeLimit;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;

/**
 * One kind of node a post document may hold: what it accepts, how it is checked and how it is
 * shown. The document schema only knows the types registered with it, so a new node (a dice
 * roll, an out-of-character aside) is one class here plus its TipTap extension in the editor.
 *
 * Text runs are not a type: the schema checks them itself, with their marks.
 */
interface NodeTypeInterface
{
    /** The node's `type` in the document, as TipTap names it (camelCase) */
    public function name(): string;

    public function group(): NodeGroup;

    /**
     * What it may contain: node names, the groups `block` and `inline`, or `text` for bare text
     * runs. Null for a leaf, which never has content.
     *
     * @return list<string>|null
     */
    public function content(): ?array;

    /** Whether it needs at least one child (a list needs an item) */
    public function requiresContent(): bool;

    /** Whether the text and inline nodes inside it may carry marks */
    public function allowsMarks(): bool;

    /**
     * Whether it gives a post something to read on its own, so a post made only of it is not
     * empty (an image is; a separator is not).
     */
    public function isSubstantial(): bool;

    /** How many of it a document may hold; null for no limit of its own */
    public function limit(): ?NodeLimit;

    /**
     * Checks its attributes: every key known, every value of the right type. Optional ones may
     * be missing or null. Nothing is changed.
     *
     * @param  array<array-key, mixed>  $attributes  the node's `attrs`, empty when it has none
     *
     * @throws InvalidDocumentException
     */
    public function validateAttributes(array $attributes, string $path): void;

    /**
     * The node as HTML. Every piece of text that goes into it must be escaped here; the
     * children come already rendered.
     *
     * @param  array<string, mixed>  $attributes  already validated
     * @param  list<string>  $children
     */
    public function toHtml(array $attributes, array $children, RenderContext $context): string;

    /**
     * The node as plain text, for search, summaries and mails.
     *
     * @param  array<string, mixed>  $attributes  already validated
     * @param  list<string>  $children
     */
    public function toText(array $attributes, array $children, RenderContext $context): string;
}
