import { mount } from '@vue/test-utils';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import ReportDialog from '../../../resources/js/components/moderation/ReportDialog.vue';

beforeAll(() => {
    // jsdom has no modal dialogs
    HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
        this.open = false;
    };
});

afterEach(() => {
    vi.unstubAllGlobals();
});

function element<T extends HTMLElement>(testId: string): T {
    const found = document.body.querySelector<T>(`[data-testid="${testId}"]`);
    if (found === null) {
        throw new Error(`No ${testId}`);
    }

    return found;
}

describe('ReportDialog', () => {
    it('sends a reason from the list and the note', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue(new Response(JSON.stringify({ reported: true }), { status: 201 }));
        vi.stubGlobal('fetch', fetch);
        const wrapper = mount(ReportDialog, {
            props: { modelValue: false, reportUrl: '/foro/mensajes/p-1/denuncias', postNumber: 3 },
            attachTo: document.body,
        });
        await wrapper.setProps({ modelValue: true });

        expect(element<HTMLButtonElement>('report-submit').disabled).toBe(true);

        element<HTMLInputElement>('report-reason-spam').click();
        const note = element<HTMLTextAreaElement>('report-note');
        note.value = '  Enlaces a una tienda ';
        note.dispatchEvent(new Event('input'));
        await nextTick();
        element<HTMLButtonElement>('report-submit')
            .closest('form')
            ?.dispatchEvent(new Event('submit'));
        await vi.waitFor(() => expect(wrapper.emitted('reported')).toHaveLength(1));

        expect(fetch).toHaveBeenCalledWith(
            '/foro/mensajes/p-1/denuncias',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ reason: 'spam', note: 'Enlaces a una tienda' }),
            })
        );
    });
});
