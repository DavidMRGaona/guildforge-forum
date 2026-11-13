<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Application\Content\NodeLimit;
use Modules\Forum\Application\Content\Rendering\Html;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Application\Content\Rendering\RollView;
use Modules\Forum\Domain\Dice\Limits;
use Modules\Forum\Domain\Dice\RollResult;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;

/**
 * A dice roll. The node holds the expression and the reason; the result is never in the
 * document: the server throws the dice when the post is published, keeps the throw apart and
 * writes only its id (`rollId`) into the node. A node that comes from the editor has no
 * `rollId`; one that has it is checked against the post's own rolls when it is stored, so
 * nobody can bring a result (or another post's roll) in a document.
 *
 * `hidden` asks for a hidden roll (a new one only: whether a stored roll is hidden is the
 * roll's, never the document's). Whoever the rules keep out sees «Tirada oculta: 2d6+3 Ataque»:
 * the expression and the reason, never a die or the total, in HTML and in plain text alike.
 */
final class RollNode extends AbstractNodeType
{
    /** The id of a stored roll, as the server hands it out */
    public const string ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public function name(): string
    {
        return 'roll';
    }

    public function isSubstantial(): bool
    {
        return true;
    }

    public function limit(): NodeLimit
    {
        return new NodeLimit(DocumentLimits::MAX_ROLLS, InvalidDocumentException::TOO_MANY_ROLLS);
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, ['expression', 'reason', 'rollId', 'hidden'], $path);
        Attributes::optionalBool($attributes, 'hidden', $path);
        // An expression that does not read is not refused here: it is shown with its error
        Attributes::optionalText($attributes, 'expression', Limits::MAX_EXPRESSION_LENGTH, $path);
        Attributes::optionalText($attributes, 'reason', Limits::MAX_REASON_LENGTH, $path);
        Attributes::optionalMatching($attributes, 'rollId', self::ID_PATTERN, $path);
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        $expression = Attributes::string($attributes, 'expression') ?? '';
        $reason = Attributes::string($attributes, 'reason') ?? '';
        $rollId = Attributes::string($attributes, 'rollId');
        $roll = $rollId === null ? null : $context->roll($rollId);
        $speaker = $context->speaker();

        // Kept from this reader: what anyone may know, and nothing else
        if ($roll instanceof RollView && $roll->withheld) {
            $label = $speaker === null ? __('forum::messages.dice.hidden.label') : __('forum::messages.dice.hidden.speaker_rolls', ['name' => $speaker]);

            return '<div class="forum-roll forum-roll--hidden" data-roll-id="'.Html::escape($roll->id).'">'
                .$this->head($label, $expression, $reason, $roll)
                .'<p class="forum-roll__secret" role="note">'.Html::escape(__('forum::messages.dice.hidden.withheld')).'</p></div>';
        }

        $label = $speaker === null ? __('forum::messages.dice.label') : __('forum::messages.dice.speaker_rolls', ['name' => $speaker]);
        $head = $this->head($label, $expression, $reason, $roll);

        if ($roll instanceof RollView && $roll->result instanceof RollResult) {
            return '<div class="forum-roll'.($roll->isSecretForOthers() ? ' forum-roll--secret' : '').'" data-roll-id="'.Html::escape($roll->id).'">'.$head
                .'<p class="forum-roll__dice">'.$this->diceHtml($roll->result).'</p>'
                .'<p class="forum-roll__total"><span class="forum-roll__total-label">'.Html::escape(__('forum::messages.dice.total')).'</span> = <strong>'.$roll->result->total.'</strong></p>'
                .$this->hiddenHtml($roll)
                .'</div>';
        }

        $problem = $roll instanceof RollView && $roll->error !== null
            ? __('forum::messages.dice.errors.'.$roll->error)
            : __('forum::messages.dice.unresolved');

        return '<div class="forum-roll forum-roll--error"'.($rollId === null ? '' : ' data-roll-id="'.Html::escape($rollId).'"').'>'.$head
            .'<p class="forum-roll__problem" role="note">'.Html::escape($problem).'</p>'
            .($roll instanceof RollView ? $this->hiddenHtml($roll) : '')
            .'</div>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        $expression = Attributes::string($attributes, 'expression') ?? '';
        $reason = Attributes::string($attributes, 'reason') ?? '';
        $rollId = Attributes::string($attributes, 'rollId');
        $roll = $rollId === null ? null : $context->roll($rollId);
        $label = trim($expression.($reason === '' ? '' : ' '.$reason));

        if (! $roll instanceof RollView) {
            return $label;
        }

        if ($roll->withheld) {
            return __('forum::messages.dice.hidden.text', ['roll' => $label]);
        }

        if ($roll->result instanceof RollResult) {
            return $label.' = '.$roll->result->total;
        }

        return $label.' ('.__('forum::messages.dice.errors.'.($roll->error ?? 'syntax')).')';
    }

    private function head(string $label, string $expression, string $reason, ?RollView $roll): string
    {
        return '<p class="forum-roll__head"><span class="forum-roll__label">'.Html::escape($label).'</span> '
            .'<code class="forum-roll__expression">'.Html::escape($expression).'</code>'
            .($reason === '' ? '' : ' <span class="forum-roll__reason">'.Html::escape($reason).'</span>')
            .($roll?->isSecretForOthers() === true ? ' <span class="forum-roll__badge forum-roll__badge--hidden">'.Html::escape(__('forum::messages.dice.hidden.badge')).'</span>' : '')
            .($roll?->addedOnEdit === true ? ' <span class="forum-roll__badge">'.Html::escape(__('forum::messages.dice.added_on_edit')).'</span>' : '')
            .'</p>';
    }

    /**
     * What a reader who sees a hidden roll is told about it: who else sees it and, when they may,
     * the button to reveal it; once revealed, who did and when.
     */
    private function hiddenHtml(RollView $roll): string
    {
        if ($roll->isSecretForOthers()) {
            $note = __($roll->inCampaignSpace ? 'forum::messages.dice.hidden.seen_by_campaign' : 'forum::messages.dice.hidden.seen_by');

            return '<div class="forum-roll__hidden-bar"><p class="forum-roll__secret" role="note">'.Html::escape($note).'</p>'
                .($roll->canReveal
                    ? '<button type="button" class="forum-roll__reveal" data-roll-reveal="'.Html::escape($roll->id).'">'.Html::escape(__('forum::messages.dice.hidden.reveal')).'</button>'
                    : '')
                .'</div>';
        }

        if ($roll->revealed) {
            $text = $roll->revealedBy !== null && $roll->revealedAt !== null
                ? __('forum::messages.dice.hidden.revealed_by', ['name' => $roll->revealedBy, 'date' => $roll->revealedAt])
                : __('forum::messages.dice.hidden.revealed');

            return '<p class="forum-roll__revealed">'.Html::escape($text).'</p>';
        }

        return '';
    }

    /**
     * Every term: dice with the dropped ones struck through and the exploded ones marked,
     * constants as they are, joined by their signs. The marks are also said in words, so the
     * result reads the same without sight of the strike or the colour.
     */
    private function diceHtml(RollResult $result): string
    {
        $multiple = count($result->terms) > 1;
        $html = '';

        foreach ($result->terms as $index => $term) {
            $negative = ($term['sign'] ?? 1) === -1;
            if ($index > 0 || $negative) {
                $html .= '<span class="forum-roll__op">'.($negative ? '−' : '+').'</span> ';
            }

            if (($term['kind'] ?? '') === 'const') {
                $value = $term['value'] ?? 0;
                $html .= '<span class="forum-roll__const">'.(is_int($value) ? $value : 0).'</span> ';

                continue;
            }

            $spec = is_string($term['spec'] ?? null) ? $term['spec'] : '';
            $dice = is_array($term['dice'] ?? null) ? $term['dice'] : [];
            $subtotal = $term['subtotal'] ?? 0;
            $successes = ($term['mode'] ?? '') === 'successes';

            $html .= '<span class="forum-roll__group" role="group" aria-label="'.Html::escape($spec).'">';
            foreach ($dice as $die) {
                if (is_array($die)) {
                    $html .= $this->dieHtml($die);
                }
            }
            if ($multiple || $successes) {
                $html .= '<span class="forum-roll__subtotal">('.(is_int($subtotal) ? $subtotal : 0).($successes ? ' '.Html::escape(__('forum::messages.dice.successes')) : '').')</span>';
            }
            $html .= '</span> ';
        }

        return rtrim($html);
    }

    /**
     * @param  array<array-key, mixed>  $die
     */
    private function dieHtml(array $die): string
    {
        $value = is_int($die['v'] ?? null) ? $die['v'] : 0;
        $dropped = ($die['k'] ?? true) === false;
        $exploded = ($die['x'] ?? false) === true;

        $classes = 'forum-roll__die'.($dropped ? ' forum-roll__die--dropped' : '').($exploded ? ' forum-roll__die--exploded' : '');
        $notes = ($dropped ? ' ('.__('forum::messages.dice.dropped').')' : '').($exploded ? ' ('.__('forum::messages.dice.exploded').')' : '');
        $inner = $value.($notes === '' ? '' : '<span class="forum-roll__note">'.Html::escape($notes).'</span>');

        return $dropped
            ? '<s class="'.$classes.'">'.$inner.'</s>'
            : '<span class="'.$classes.'">'.$inner.'</span>';
    }
}
