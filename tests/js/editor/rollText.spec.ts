import { describe, expect, it } from 'vitest';
import {
    MAX_EXPRESSION_LENGTH,
    MAX_REASON_LENGTH,
    rollFromShortcut,
    splitRollText,
} from '../../../resources/js/editor/rollText';

describe('splitting a roll line', () => {
    it.each([
        ['2d6+3 Ataque', '2d6+3', 'Ataque'],
        ['2d6+3 Ataque con espada', '2d6+3', 'Ataque con espada'],
        ['d20', 'd20', ''],
        ['1d20 + 5 - 2 Iniciativa', '1d20+5-2', 'Iniciativa'],
        ['1d20 +5 Iniciativa', '1d20+5', 'Iniciativa'],
        ['1d20 + daño', '1d20', '+ daño'],
        ['4dF', '4dF', ''],
        ['d% porcentaje', 'd%', 'porcentaje'],
        ['5d10>=7 Percepción', '5d10>=7', 'Percepción'],
        ['5d10>=8f=1', '5d10>=8f=1', ''],
        ['4d6kh3 atributo', '4d6kh3', 'atributo'],
        ['2d20kl1: desventaja', '2d20kl1', 'desventaja'],
        ['3d10!>=9 explosivo', '3d10!>=9', 'explosivo'],
        ['3d6+1d4', '3d6+1d4', ''],
        ['3d6 + 1d4 daño total', '3d6+1d4', 'daño total'],
        ['2d6 3 veces', '2d6', '3 veces'],
        ['   2d6    Ataque  ', '2d6', 'Ataque'],
        ['sigilo', '', 'sigilo'],
        ['dado de la suerte', '', 'dado de la suerte'],
        ['', '', ''],
    ])('reads «%s»', (text, expression, reason) => {
        expect(splitRollText(text)).toEqual({ expression, reason });
    });

    it('cuts what is longer than the server accepts', () => {
        const long = splitRollText(`${'1+'.repeat(200)}1 ${'palabra '.repeat(30)}`);

        expect(long.expression.length).toBe(MAX_EXPRESSION_LENGTH);
        expect(long.reason.length).toBeLessThanOrEqual(MAX_REASON_LENGTH);
    });
});

describe('the /tirada shortcut', () => {
    it('reads a line that starts with it, whatever the case', () => {
        expect(rollFromShortcut('/tirada 2d6+3 Ataque')).toEqual({
            expression: '2d6+3',
            reason: 'Ataque',
        });
        expect(rollFromShortcut('  /Tirada   d20 ')).toEqual({ expression: 'd20', reason: '' });
        expect(rollFromShortcut('/tirada')).toEqual({ expression: '', reason: '' });
    });

    it('leaves any other line alone', () => {
        expect(rollFromShortcut('tira 2d6')).toBeNull();
        expect(rollFromShortcut('/tiradas 2d6')).toBeNull();
        expect(rollFromShortcut('Hoy /tirada 2d6')).toBeNull();
    });
});
