import { afterEach, describe, expect, it } from 'vitest';
import { selectionText } from '../../../resources/js/forum/selectionText';

function body(html: string): HTMLElement {
    const element = document.createElement('div');
    element.innerHTML = html;
    document.body.append(element);

    return element;
}

function rangeOver(node: Node): Range {
    const range = document.createRange();
    range.selectNodeContents(node);

    return range;
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the text of a selection in a post', () => {
    it('leaves out references, which the stored text does not hold', () => {
        const post = body('<p>Como dice <a class="forum-ref" data-post-id="x">#3</a>, sí</p>');

        expect(selectionText(rangeOver(post))).toBe('Como dice , sí');
    });

    it('leaves out the posts expanded inside it', () => {
        const post = body(
            '<p>Antes</p><div class="forum-ref-preview"><p>Lo que dijo otra persona</p></div><p>Después</p>'
        );

        expect(selectionText(rangeOver(post))).toBe('AntesDespués');
    });

    it('is empty for a selection inside an expanded post', () => {
        const post = body('<div class="forum-ref-preview"><p>Lo que dijo otra persona</p></div>');

        expect(selectionText(rangeOver(post.querySelector('p')!))).toBe('');
    });
});
