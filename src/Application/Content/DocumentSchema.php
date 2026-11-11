<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content;

use LogicException;
use Modules\Forum\Application\Content\Contracts\MarkTypeInterface;
use Modules\Forum\Application\Content\Contracts\NodeTypeInterface;
use Modules\Forum\Application\Content\Marks\LinkMark;
use Modules\Forum\Application\Content\Marks\SimpleMark;
use Modules\Forum\Application\Content\Nodes\BlockquoteNode;
use Modules\Forum\Application\Content\Nodes\BulletListNode;
use Modules\Forum\Application\Content\Nodes\CodeBlockNode;
use Modules\Forum\Application\Content\Nodes\HardBreakNode;
use Modules\Forum\Application\Content\Nodes\HeadingNode;
use Modules\Forum\Application\Content\Nodes\HorizontalRuleNode;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Content\Nodes\ListItemNode;
use Modules\Forum\Application\Content\Nodes\MentionNode;
use Modules\Forum\Application\Content\Nodes\OrderedListNode;
use Modules\Forum\Application\Content\Nodes\ParagraphNode;
use Modules\Forum\Application\Content\Nodes\PostQuoteNode;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;

/**
 * The post document schema: the closed list of nodes and marks a post may hold, and the
 * checks a document passes before it is stored. It follows ProseMirror's JSON (what TipTap's
 * getJSON() gives): `{type, attrs?, content?, marks?, text?}` from a root of type `doc`.
 *
 * A document is refused, never repaired: an unknown node, mark, key or attribute, a node where
 * its parent does not take it, empty text, a link to anything but http, https or mailto, an
 * image from outside the forum, or a document too large, too deep or with too many nodes.
 */
final class DocumentSchema
{
    public const string ROOT = 'doc';

    public const string TEXT = 'text';

    private const array NODE_KEYS = ['type', 'attrs', 'content', 'marks'];

    private const array TEXT_KEYS = ['type', 'text', 'marks'];

    private const array MARK_KEYS = ['type', 'attrs'];

    /** @var array<string, NodeTypeInterface> */
    private array $nodes = [];

    /** @var array<string, MarkTypeInterface> */
    private array $marks = [];

    /** Nodes and text runs seen in the document being checked */
    private int $nodeCount = 0;

    /** @var array<string, int> */
    private array $typeCounts = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $collected = [];

    private bool $hasContent = false;

    /**
     * @param  iterable<NodeTypeInterface>  $nodes
     * @param  iterable<MarkTypeInterface>  $marks
     *
     * @throws LogicException when two types share a name
     */
    public function __construct(iterable $nodes, iterable $marks)
    {
        foreach ($nodes as $node) {
            if (isset($this->nodes[$node->name()]) || in_array($node->name(), [self::ROOT, self::TEXT, NodeGroup::Block->value, NodeGroup::Inline->value], true)) {
                throw new LogicException("The post document already has a node called '{$node->name()}'.");
            }
            $this->nodes[$node->name()] = $node;
        }

        foreach ($marks as $mark) {
            if (isset($this->marks[$mark->name()])) {
                throw new LogicException("The post document already has a mark called '{$mark->name()}'.");
            }
            $this->marks[$mark->name()] = $mark;
        }
    }

    /**
     * The nodes and marks of the editor's core, without the ones other features add.
     */
    public static function core(): self
    {
        return new self(self::coreNodes(), self::coreMarks());
    }

    /**
     * @return list<NodeTypeInterface>
     */
    public static function coreNodes(): array
    {
        return [
            new ParagraphNode,
            new HeadingNode,
            new BlockquoteNode,
            new PostQuoteNode,
            new BulletListNode,
            new OrderedListNode,
            new ListItemNode,
            new CodeBlockNode,
            new HorizontalRuleNode,
            new ImageNode,
            new HardBreakNode,
            new MentionNode,
        ];
    }

    /**
     * @return list<MarkTypeInterface>
     */
    public static function coreMarks(): array
    {
        return [SimpleMark::bold(), SimpleMark::italic(), SimpleMark::strike(), SimpleMark::code(), new LinkMark];
    }

    public function node(string $name): ?NodeTypeInterface
    {
        return $this->nodes[$name] ?? null;
    }

    public function mark(string $name): ?MarkTypeInterface
    {
        return $this->marks[$name] ?? null;
    }

    /**
     * @throws InvalidDocumentException
     */
    public function validate(mixed $raw): PostDocument
    {
        if (! is_array($raw) || array_is_list($raw) || ($raw['type'] ?? null) !== self::ROOT) {
            throw InvalidDocumentException::because(InvalidDocumentException::NOT_A_DOCUMENT, '', 'A post is an editor document');
        }

        $encoded = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw InvalidDocumentException::because(InvalidDocumentException::NOT_A_DOCUMENT, '', 'A post is an editor document');
        }
        if (strlen($encoded) > DocumentLimits::MAX_BYTES) {
            throw InvalidDocumentException::because(InvalidDocumentException::TOO_LARGE, '', 'The document is too large');
        }

        $this->nodeCount = 1;
        $this->typeCounts = [];
        $this->collected = [];
        $this->hasContent = false;

        $this->assertKeys($raw, ['type', 'attrs', 'content'], '');
        if (isset($raw['attrs']) && $raw['attrs'] !== []) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_ATTRIBUTE, '', 'The document has no attributes');
        }
        $this->walkChildren($raw['content'] ?? null, [NodeGroup::Block->value], true, false, '', 1);

        if (! $this->hasContent) {
            throw InvalidDocumentException::because(InvalidDocumentException::EMPTY, '', 'The post is empty');
        }

        /** @var array<string, mixed> $raw */
        return new PostDocument($raw, $this->collected);
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws InvalidDocumentException
     */
    private function walkChildren(mixed $content, array $allowed, bool $required, bool $allowsMarks, string $path, int $depth): void
    {
        if ($content === null || $content === []) {
            if ($required) {
                throw InvalidDocumentException::because(InvalidDocumentException::MISSING_CONTENT, $path, 'This node needs content');
            }

            return;
        }

        if (! is_array($content) || ! array_is_list($content)) {
            throw InvalidDocumentException::because(InvalidDocumentException::NOT_ALLOWED_HERE, $path, 'Content is a list of nodes');
        }

        foreach ($content as $index => $child) {
            $this->walkNode($child, $allowed, $allowsMarks, ltrim("{$path}.content.{$index}", '.'), $depth + 1);
        }
    }

    /**
     * @param  list<string>  $allowed  what the parent takes
     *
     * @throws InvalidDocumentException
     */
    private function walkNode(mixed $node, array $allowed, bool $allowsMarks, string $path, int $depth): void
    {
        if ($depth > DocumentLimits::MAX_DEPTH) {
            throw InvalidDocumentException::because(InvalidDocumentException::TOO_DEEP, $path, 'The document is nested too deep');
        }
        if (++$this->nodeCount > DocumentLimits::MAX_NODES) {
            throw InvalidDocumentException::because(InvalidDocumentException::TOO_MANY_NODES, $path, 'The document has too many nodes');
        }

        $type = is_array($node) ? ($node['type'] ?? null) : null;
        if (! is_array($node) || ! is_string($type)) {
            throw InvalidDocumentException::because(InvalidDocumentException::UNKNOWN_NODE, $path, 'Not a node');
        }

        if ($type === self::TEXT) {
            $this->walkText($node, $allowed, $allowsMarks, $path);

            return;
        }

        $nodeType = $this->nodes[$type] ?? throw InvalidDocumentException::because(InvalidDocumentException::UNKNOWN_NODE, $path, "Unknown node '{$type}'");

        if (! in_array($type, $allowed, true) && ! in_array($nodeType->group()->value, $allowed, true)) {
            throw InvalidDocumentException::because(InvalidDocumentException::NOT_ALLOWED_HERE, $path, "'{$type}' cannot go here");
        }

        $this->assertKeys($node, self::NODE_KEYS, $path);
        if (array_key_exists('marks', $node)) {
            if ($nodeType->group() !== NodeGroup::Inline) {
                throw InvalidDocumentException::because(InvalidDocumentException::UNEXPECTED_KEY, $path, 'Only text and inline nodes carry marks');
            }
            $this->walkMarks($node['marks'], $allowsMarks, $path);
        }

        $attributes = $node['attrs'] ?? [];
        if (! is_array($attributes)) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_ATTRIBUTE, $path, 'Attributes are an object');
        }
        $nodeType->validateAttributes($attributes, $path);

        $limit = $nodeType->limit();
        $this->typeCounts[$type] = ($this->typeCounts[$type] ?? 0) + 1;
        if ($limit instanceof NodeLimit && $this->typeCounts[$type] > $limit->max) {
            throw InvalidDocumentException::because($limit->reason, $path, "Too many '{$type}' nodes");
        }

        /** @var array<string, mixed> $attributes */
        $this->collected[$type][] = $attributes;
        $this->hasContent = $this->hasContent || $nodeType->isSubstantial();

        $childTypes = $nodeType->content();
        if ($childTypes === null) {
            if (array_key_exists('content', $node)) {
                throw InvalidDocumentException::because(InvalidDocumentException::NOT_ALLOWED_HERE, $path, "'{$type}' has no content");
            }

            return;
        }

        $this->walkChildren($node['content'] ?? null, $childTypes, $nodeType->requiresContent(), $nodeType->allowsMarks(), $path, $depth);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $allowed
     *
     * @throws InvalidDocumentException
     */
    private function walkText(array $node, array $allowed, bool $allowsMarks, string $path): void
    {
        if (! in_array(self::TEXT, $allowed, true) && ! in_array(NodeGroup::Inline->value, $allowed, true)) {
            throw InvalidDocumentException::because(InvalidDocumentException::NOT_ALLOWED_HERE, $path, 'Text cannot go here');
        }

        $this->assertKeys($node, self::TEXT_KEYS, $path);

        $text = $node['text'] ?? null;
        if (! is_string($text) || $text === '' || str_contains($text, "\0")) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_TEXT, $path, 'Text runs are not empty');
        }

        if (array_key_exists('marks', $node)) {
            $this->walkMarks($node['marks'], $allowsMarks, $path);
        }

        $this->hasContent = $this->hasContent || preg_match('/\S/u', $text) === 1;
    }

    /**
     * @throws InvalidDocumentException
     */
    private function walkMarks(mixed $marks, bool $allowsMarks, string $path): void
    {
        if ($marks === []) {
            return;
        }
        if (! $allowsMarks) {
            throw InvalidDocumentException::because(InvalidDocumentException::NOT_ALLOWED_HERE, $path, 'No marks here');
        }
        if (! is_array($marks) || ! array_is_list($marks)) {
            throw InvalidDocumentException::because(InvalidDocumentException::NOT_ALLOWED_HERE, $path, 'Marks are a list');
        }

        $seen = [];
        foreach ($marks as $index => $mark) {
            $markPath = "{$path}.marks.{$index}";
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            if (! is_array($mark) || ! is_string($type) || ! isset($this->marks[$type])) {
                throw InvalidDocumentException::because(InvalidDocumentException::UNKNOWN_MARK, $markPath, 'Unknown mark');
            }

            $this->assertKeys($mark, self::MARK_KEYS, $markPath);
            if (isset($seen[$type])) {
                throw InvalidDocumentException::because(InvalidDocumentException::NOT_ALLOWED_HERE, $markPath, "'{$type}' twice");
            }
            $seen[$type] = true;

            $attributes = $mark['attrs'] ?? [];
            if (! is_array($attributes)) {
                throw InvalidDocumentException::because(InvalidDocumentException::INVALID_ATTRIBUTE, $markPath, 'Attributes are an object');
            }
            $this->marks[$type]->validateAttributes($attributes, $markPath);
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $allowed
     *
     * @throws InvalidDocumentException
     */
    private function assertKeys(array $node, array $allowed, string $path): void
    {
        foreach (array_keys($node) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw InvalidDocumentException::because(InvalidDocumentException::UNEXPECTED_KEY, $path, "Unexpected key '{$key}'");
            }
        }
    }
}
