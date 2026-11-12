import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetKnowledgeVisibility } from '@/composables/useKnowledgeVisibility';
import PostEditor from '../../../resources/js/components/editor/PostEditor.vue';
import Index from '../../../resources/js/pages/Forum/Index.vue';
import { signIn } from '../support/page';

/**
 * The «?» links to the help guides: in the editor and next to the forum's title. They show only
 * when the site's visibility endpoint lists the guide, so a hidden or missing guide leaves no link.
 */
function offerGuides(ids: string[]): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ ids }) }))
    );
}

function indexProps(): Record<string, unknown> {
    return {
        categories: [],
        discussions: { items: [], nextUrl: null, previousUrl: null, total: 0 },
        filters: { show: 'all', topic: null, topics: [] },
        search: { url: '/foro/buscar', categories: [] },
        canStart: false,
        newDiscussionUrl: '/foro/nuevo',
        markReadUrl: null,
        moderationUrl: null,
    };
}

async function mountIndex(): Promise<VueWrapper> {
    const wrapper = mount(Index, {
        props: indexProps() as never,
        global: {
            stubs: {
                CategoryList: true,
                DiscussionList: true,
                DiscussionFilters: true,
                ForumSearchForm: true,
            },
        },
    });
    await flushPromises();

    return wrapper;
}

async function mountEditor(): Promise<VueWrapper> {
    const wrapper = mount(PostEditor, {
        props: { label: 'Tu respuesta', submitLabel: 'Responder' },
        attachTo: document.body,
    });
    await flushPromises();

    return wrapper;
}

beforeEach(() => {
    resetKnowledgeVisibility();
    signIn();
    localStorage.clear();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('help links', () => {
    it('shows the writing guide in the editor when the person may read it', async () => {
        offerGuides(['forum.site.writing']);
        const wrapper = await mountEditor();

        const link = wrapper.find('a[href*="forum.site.writing"]');
        expect(link.exists()).toBe(true);
    });

    it('leaves no link in the editor when the guide is not visible', async () => {
        offerGuides(['forum.site.overview']);
        const wrapper = await mountEditor();

        expect(wrapper.find('a[href*="forum.site.writing"]').exists()).toBe(false);
    });

    it('shows the overview guide next to the forum title', async () => {
        offerGuides(['forum.site.overview']);
        const wrapper = await mountIndex();

        expect(wrapper.find('h1 a[href*="forum.site.overview"]').exists()).toBe(true);
    });

    it('leaves no link on the forum index when the guide is hidden', async () => {
        offerGuides([]);
        const wrapper = await mountIndex();

        expect(wrapper.find('a[href*="forum.site.overview"]').exists()).toBe(false);
    });
});
