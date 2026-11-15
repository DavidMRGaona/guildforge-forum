/**
 * The text of a selection in a post as its stored text has it: without the references
 * (stored without their number) nor the posts expanded inside the body (someone else's words).
 */
export function selectionText(range: Range): string {
    const contents = range.cloneContents();
    contents
        .querySelectorAll('.forum-ref, .forum-ref-preview')
        .forEach((element) => element.remove());

    // A selection starting inside an expanded post copies only its inner part: leave it out too
    const start =
        range.startContainer instanceof Element
            ? range.startContainer
            : range.startContainer.parentElement;
    if (start?.closest('.forum-ref-preview')) {
        return '';
    }

    return (contents.textContent ?? '').trim();
}
