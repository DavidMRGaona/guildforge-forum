/**
 * The editor's side of the dice notation (src/Domain/Dice on the server). The server is the
 * only judge of an expression and reads it again when the post is published; here the text of
 * a `/tirada` line is only split into the expression and the reason, so the node starts with
 * what the person meant and a typo is shown by the server as an error, never published as a
 * result.
 */

/** DocumentLimits / Domain\Dice\Limits: the longest expression and reason, in characters */
export const MAX_EXPRESSION_LENGTH = 200;
export const MAX_REASON_LENGTH = 100;

/** Rolls per post (Domain\Dice\Limits::MAX_ROLLS_PER_POST) */
export const MAX_ROLLS = 10;

/** What a person types to start a roll on a line of its own; «oculta» first makes it hidden */
const SHORTCUT = /^\/tirada(?:\s+(.*))?$/iu;

/** The word that asks for a hidden roll, right after «/tirada» */
const HIDDEN_WORD = /^oculta(?:\s+|$)/iu;

/** A group of dice or a number, with whatever modifiers follow it, glued together */
const TERM =
    /^(?:\d+|\d*d(?:\d+|%|f))[0-9a-z!<>=%]*(?:[+-](?:\d+|\d*d(?:\d+|%|f))[0-9a-z!<>=%]*)*$/iu;

/** A sign that opens a term: «+3», «-1d4», or a lone «+» that the next word completes */
const SIGNED_TERM =
    /^[+-](?:\d+|\d*d(?:\d+|%|f))[0-9a-z!<>=%]*(?:[+-](?:\d+|\d*d(?:\d+|%|f))[0-9a-z!<>=%]*)*$/iu;

export interface RollText {
    expression: string;
    reason: string;
}

/** A roll asked for with `/tirada`: also whether it is hidden */
export interface RollShortcut extends RollText {
    hidden: boolean;
}

/**
 * The text of a paragraph when it is a `/tirada` line, else null. «/tirada» alone gives an
 * empty roll to fill in; «/tirada oculta 1d20 Sigilo» a hidden one (its result is shown only to
 * whoever throws it, moderation and a campaign's direction).
 */
export function rollFromShortcut(paragraph: string): RollShortcut | null {
    const match = SHORTCUT.exec(paragraph.trim());
    if (match === null) {
        return null;
    }

    const rest = (match[1] ?? '').trim();
    const hidden = HIDDEN_WORD.test(rest);

    return { ...splitRollText(hidden ? rest.replace(HIDDEN_WORD, '') : rest), hidden };
}

/**
 * Splits «2d6+3 Ataque con espada» into the expression «2d6+3» and the reason «Ataque con
 * espada». The expression is what reads like dice from the start: terms joined by signs, with
 * spaces allowed around them while what follows is another term («1d20 + 5 Iniciativa»);
 * everything else is the reason. A reason may start after a colon or comma.
 */
export function splitRollText(text: string): RollText {
    const words = text
        .trim()
        .split(/\s+/u)
        .filter((word) => word !== '');
    const taken: string[] = [];
    let index = 0;

    // A colon, comma or semicolon right after the last word of the expression closes it
    const closes = (word: string): boolean => /[:,;]$/u.test(word);
    const bare = (word: string): string => word.replace(/[:,;]+$/u, '');

    const first = words[0] ?? '';
    if (TERM.test(bare(first))) {
        taken.push(bare(first));
        index = 1;

        while (index < words.length && !closes(words[index - 1] ?? '')) {
            const word = bare(words[index] ?? '');
            const next = bare(words[index + 1] ?? '');

            if (SIGNED_TERM.test(word)) {
                taken.push(word);
                index += 1;
            } else if ((word === '+' || word === '-') && TERM.test(next)) {
                taken.push(word + next);
                index += 2;
            } else {
                break;
            }
        }
    }

    return {
        expression: taken.join('').slice(0, MAX_EXPRESSION_LENGTH),
        reason: words
            .slice(index)
            .join(' ')
            .replace(/^[\s:,;]+/u, '')
            .slice(0, MAX_REASON_LENGTH)
            .trim(),
    };
}
