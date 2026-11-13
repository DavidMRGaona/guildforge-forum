<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Forum\Application\Content\Nodes\RollNode;
use Modules\Forum\Domain\Dice\InvalidNotation;
use Modules\Forum\Domain\Dice\Limits;
use Modules\Forum\Domain\Dice\NumberGenerator;
use Modules\Forum\Domain\Dice\Parser;
use Modules\Forum\Domain\Dice\Roller;
use Modules\Forum\Domain\Entities\DiceRoll;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Repositories\DiceRollRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Throws the dice of a post when it is written. The editor sends each roll as an expression and
 * a reason; here the server throws it (once) and puts only the roll's id in the document.
 *
 * Editing never throws a roll again: a node that carries the id of one of the post's own rolls
 * (and the expression it was thrown with) is the same roll; a node without id is new, thrown
 * once and marked as added on editing; a roll that is no longer in the document is marked
 * removed and kept. A node with an id the post does not own (a made-up one, another post's, a
 * removed roll's) or a different expression refuses the whole document: nobody can bring a
 * result in.
 *
 * A roll that does not read, or that would pass the message's dice budget, is not thrown: it is
 * stored with its error and shown with it, and the rest of the post is published all the same.
 *
 * A new roll is hidden when its node asks for it (`hidden`), and whoever writes the body is its
 * thrower. A roll the post keeps stays as it was thrown, hidden or not, whatever the node says:
 * a public result cannot be hidden afterwards, nor a hidden one shown but by revealing it. The
 * plain text stored with the post never holds the result of a roll still secret.
 */
final readonly class PostRolls
{
    public function __construct(
        private DiceRollRepositoryInterface $rolls,
        private NumberGenerator $numbers,
        private PostRenderer $renderer,
    ) {}

    /**
     * Settles the rolls of a body about to be written for the post.
     *
     * @param  bool  $editing  whether the post already exists (its rolls are the ones it may keep)
     * @param  string|null  $rolledBy  the account writing the body: whoever throws its new rolls
     *
     * @throws InvalidDocumentException when a roll does not belong to the post, or the text gets too long
     */
    public function settle(PostId $postId, PostBody $body, bool $editing, DateTimeImmutable $at, ?string $rolledBy = null): RolledBody
    {
        $nodes = [];
        $this->collect($body->document, $nodes);

        $stored = $editing ? $this->rolls->ofPost($postId) : [];
        /** @var array<string, DiceRoll> $active */
        $active = [];
        foreach ($stored as $roll) {
            if (! $roll->isRemoved()) {
                $active[$roll->id] = $roll;
            }
        }

        if ($nodes === [] && $active === []) {
            return new RolledBody($body, []);
        }

        // Which nodes keep a roll the post already has, and how many dice those use
        $kept = [];
        $budget = 0;
        foreach ($nodes as $index => $attributes) {
            $rollId = $attributes['rollId'] ?? null;
            if ($rollId === null) {
                continue;
            }

            $roll = is_string($rollId) ? ($active[$rollId] ?? null) : null;
            if (! $roll instanceof DiceRoll || isset($kept[$roll->id]) || $roll->expression !== $this->normalized(is_string($attributes['expression'] ?? null) ? $attributes['expression'] : '')) {
                throw InvalidDocumentException::because(InvalidDocumentException::UNKNOWN_ROLL, '', 'A roll does not belong to this post');
            }

            $kept[$roll->id] = $index;
            $budget += $roll->diceCount;
        }

        $records = [];
        /** @var list<DiceRoll> $current the post's rolls once settled, in document order */
        $current = [];
        foreach ($nodes as $index => $attributes) {
            $position = $index + 1;
            $keptId = array_search($index, $kept, true);

            if ($keptId !== false) {
                $roll = $active[$keptId];
                if ($roll->position !== $position) {
                    $roll = $roll->movedTo($position);
                    $records[] = $roll;
                }
                $current[] = $roll;

                continue;
            }

            $roll = $this->throwNew($postId, $position, is_string($attributes['expression'] ?? null) ? $attributes['expression'] : '', $budget, $editing, $at, ($attributes['hidden'] ?? false) === true, $rolledBy);
            $budget += $roll->diceCount;
            $records[] = $roll;
            $current[] = $roll;
        }

        foreach ($active as $roll) {
            if (! isset($kept[$roll->id])) {
                $records[] = $roll->removedAt($at);
            }
        }

        $document = $this->withRolls($body->document, $current);
        $text = $this->renderer->plainText($document, $current);

        try {
            return new RolledBody(new PostBody($document, $text), $records);
        } catch (InvalidPostException) {
            throw InvalidDocumentException::because(InvalidDocumentException::TOO_LONG, '', 'The post is too long');
        }
    }

    /**
     * Stores what settle() decided; the post must already be saved.
     */
    public function store(RolledBody $rolled): void
    {
        foreach ($rolled->records as $record) {
            $this->rolls->save($record);
        }
    }

    /**
     * Whatever is stored when a post is emptied for good: its rolls go with it.
     */
    public function forget(PostId $postId): void
    {
        $this->rolls->deleteOfPost($postId);
    }

    private function throwNew(PostId $postId, int $position, string $written, int $budget, bool $addedOnEdit, DateTimeImmutable $at, bool $hidden, ?string $rolledBy): DiceRoll
    {
        $id = Str::uuid()->toString();
        $rolledBy = $rolledBy === null ? null : strtolower($rolledBy);

        try {
            $expression = (new Parser)->parse($written);
        } catch (InvalidNotation $exception) {
            return DiceRoll::refused($id, $postId, $position, trim($written), $exception->reason, $addedOnEdit, $at, $hidden, $rolledBy);
        }

        $dice = $expression->diceCount();
        if ($budget + $dice > Limits::MAX_DICE_PER_POST) {
            return DiceRoll::refused($id, $postId, $position, $expression->canonical(), InvalidNotation::TOO_MANY_DICE_IN_POST, $addedOnEdit, $at, $hidden, $rolledBy);
        }

        return DiceRoll::thrown($id, $postId, $position, $expression->canonical(), $dice, (new Roller($this->numbers))->roll($expression), $addedOnEdit, $at, $hidden, $rolledBy);
    }

    /**
     * How an expression is kept: the server's spelling when it reads, what was written when not.
     */
    private function normalized(string $written): string
    {
        try {
            return (new Parser)->parse($written)->canonical();
        } catch (InvalidNotation) {
            return trim($written);
        }
    }

    /**
     * The attributes of every roll node, in document order.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<array<string, mixed>>  $found
     */
    private function collect(array $node, array &$found): void
    {
        if (($node['type'] ?? null) === (new RollNode)->name()) {
            $attributes = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            /** @var array<string, mixed> $attributes */
            $found[] = $attributes;
        }

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                $this->collect($child, $found);
            }
        }
    }

    /**
     * The document with every roll node rewritten to what the server decided: the expression as
     * it reads, the reason as written, the roll's id and, for a hidden roll, `hidden` (so the
     * editor shows it). Nothing else changes.
     *
     * @param  array<array-key, mixed>  $document
     * @param  list<DiceRoll>  $rolls  in document order
     * @return array<string, mixed>
     */
    private function withRolls(array $document, array $rolls): array
    {
        $next = 0;
        $rewrite = function (array $node) use (&$rewrite, &$next, $rolls): array {
            if (($node['type'] ?? null) === (new RollNode)->name()) {
                $roll = $rolls[$next++];
                $reason = is_array($node['attrs'] ?? null) && is_string($node['attrs']['reason'] ?? null) ? trim($node['attrs']['reason']) : '';
                $node['attrs'] = ['expression' => $roll->expression, 'reason' => $reason === '' ? null : $reason, 'rollId' => $roll->id];
                if ($roll->hidden) {
                    $node['attrs']['hidden'] = true;
                }

                return $node;
            }

            if (is_array($node['content'] ?? null)) {
                $node['content'] = array_map(static fn (mixed $child): mixed => is_array($child) ? $rewrite($child) : $child, $node['content']);
            }

            return $node;
        };

        /** @var array<string, mixed> */
        return $rewrite($document);
    }
}
