import { computePosition, flip, offset, shift } from '@floating-ui/dom';
import type { SuggestionOptions, SuggestionProps } from '@tiptap/suggestion';
import { VueRenderer } from '@tiptap/vue-3';
import MentionList from './MentionList.vue';
import type { MentionCandidate } from './types';

export interface MentionSuggestionOptions {
    /** The discussion's candidates endpoint (`?q=` is added) */
    url: string;
    /** Called with each person picked, so the editor can show their name */
    onPick: (candidate: MentionCandidate) => void;
    fetcher?: typeof fetch;
}

type ListInstance = InstanceType<typeof MentionList>;

type SuggestionRenderer = ReturnType<NonNullable<SuggestionOptions<MentionCandidate>['render']>>;

/**
 * «@» looks people up in the discussion's candidates endpoint, which only answers with people
 * who may read the discussion, and shows them in a list under the caret.
 */
export function mentionSuggestion(
    options: MentionSuggestionOptions
): Omit<SuggestionOptions<MentionCandidate>, 'editor'> {
    const fetcher = options.fetcher ?? globalThis.fetch.bind(globalThis);
    let pending: AbortController | null = null;

    return {
        char: '@',
        allowSpaces: true,

        items: async ({ query }): Promise<MentionCandidate[]> => {
            pending?.abort();
            if (query.trim() === '') {
                return [];
            }

            pending = new AbortController();
            try {
                const separator = options.url.includes('?') ? '&' : '?';
                const response = await fetcher(
                    `${options.url}${separator}q=${encodeURIComponent(query)}`,
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        signal: pending.signal,
                    }
                );
                if (!response.ok) {
                    return [];
                }
                const body = (await response.json()) as { data?: MentionCandidate[] };

                return body.data ?? [];
            } catch {
                return [];
            }
        },

        command: ({ editor, range, props }): void => {
            options.onPick(props);
            editor
                .chain()
                .focus()
                .insertContentAt(range, [
                    { type: 'mention', attrs: { id: props.id } },
                    { type: 'text', text: ' ' },
                ])
                .run();
        },

        render: (): SuggestionRenderer => {
            let renderer: VueRenderer | null = null;

            const place = (props: SuggestionProps<MentionCandidate>): void => {
                const element = renderer?.element;
                const rect = props.clientRect?.();
                if (!(element instanceof HTMLElement) || rect === null || rect === undefined) {
                    return;
                }

                void computePosition({ getBoundingClientRect: () => rect }, element, {
                    placement: 'bottom-start',
                    strategy: 'fixed',
                    middleware: [offset(4), flip(), shift({ padding: 8 })],
                }).then(({ x, y }) => {
                    Object.assign(element.style, { left: `${x}px`, top: `${y}px` });
                });
            };

            return {
                onStart: (props): void => {
                    renderer = new VueRenderer(MentionList, {
                        props: { items: props.items, query: props.query, command: props.command },
                        editor: props.editor,
                    });
                    if (renderer.element instanceof HTMLElement) {
                        Object.assign(renderer.element.style, { position: 'fixed', zIndex: '50' });
                        document.body.appendChild(renderer.element);
                    }
                    place(props);
                },
                onUpdate: (props): void => {
                    renderer?.updateProps({
                        items: props.items,
                        query: props.query,
                        command: props.command,
                    });
                    place(props);
                },
                onKeyDown: ({ event }): boolean => {
                    if (event.key === 'Escape') {
                        renderer?.element?.remove();

                        return true;
                    }

                    return (renderer?.ref as ListInstance | null)?.onKeyDown(event) ?? false;
                },
                onExit: (): void => {
                    renderer?.element?.remove();
                    renderer?.destroy();
                    renderer = null;
                },
            };
        },
    };
}
