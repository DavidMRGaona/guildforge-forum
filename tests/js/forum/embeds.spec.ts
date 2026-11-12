import { afterEach, describe, expect, it } from 'vitest';
import {
    notifyCookieConsentChanged,
    registerCookieConsentProvider,
} from '@/contracts/cookieConsent';
import {
    bindEmbedButtons,
    loadConsentedEmbeds,
    loadEmbed,
    sandboxOf,
} from '../../../resources/js/composables/useEmbeds';

const CARD = `
<figure class="forum-embed" data-forum-embed data-embed-service="youtube"
    data-embed-src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0" data-embed-aspect="video"
    data-embed-referrer="strict-origin"
    data-embed-sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation"
    data-embed-title="Publicación de YouTube" data-embed-consent="videos">
    <div class="forum-embed__card"><p class="forum-embed__service">YouTube</p>
        <a class="forum-embed__link" href="https://www.youtube.com/watch?v=dQw4w9WgXcQ">Ver en YouTube</a></div>
    <div class="forum-embed__consent"><button type="button" class="forum-embed__load" data-embed-load>Cargar contenido de YouTube</button></div>
</figure>`;

function root(html = CARD): HTMLElement {
    const element = document.createElement('div');
    element.innerHTML = html;
    document.body.appendChild(element);

    return element;
}

afterEach(() => {
    document.body.innerHTML = '';
    registerCookieConsentProvider({ hasConsent: () => false });
});

describe('embedded posts', () => {
    it('load nothing of the service until the reader asks', () => {
        const element = root();

        expect(element.querySelector('iframe')).toBeNull();
        expect(element.querySelector('script')).toBeNull();
    });

    it('load the player in a sandboxed frame when the reader clicks', () => {
        const element = root();
        bindEmbedButtons(element);

        element.querySelector<HTMLButtonElement>('[data-embed-load]')?.click();

        const iframe = element.querySelector('iframe');
        expect(iframe).not.toBeNull();
        expect(iframe?.getAttribute('src')).toBe(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0'
        );
        expect(iframe?.getAttribute('sandbox')).toBe(
            'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation'
        );
        expect(iframe?.getAttribute('referrerpolicy')).toBe('strict-origin');
        expect(iframe?.getAttribute('title')).toBe('Publicación de YouTube');
        expect(element.querySelector('[data-embed-load]')).toBeNull();
        // The link to the post stays
        expect(element.querySelector('.forum-embed__link')).not.toBeNull();
        expect(document.activeElement).toBe(iframe);
    });

    it('load at once for a reader who consented to the category, and when consent comes later', () => {
        const element = root();
        let consented = false;
        registerCookieConsentProvider({ hasConsent: (slug) => consented && slug === 'videos' });

        loadConsentedEmbeds(element);
        expect(element.querySelector('iframe')).toBeNull();

        consented = true;
        notifyCookieConsentChanged();
        loadConsentedEmbeds(element);
        expect(element.querySelector('iframe')).not.toBeNull();
    });

    it('never load a player without https or an unknown referrer policy', () => {
        const element = root(
            CARD.replace('https://www.youtube-nocookie.com', 'javascript:alert(1)//').replace(
                'strict-origin',
                'unsafe-url'
            )
        );
        const figure = element.querySelector<HTMLElement>('[data-forum-embed]');

        expect(figure).not.toBeNull();
        expect(loadEmbed(figure as HTMLElement)).toBeNull();

        const other = root(CARD.replace('strict-origin', 'unsafe-url'));
        const iframe = loadEmbed(
            other.querySelector<HTMLElement>('[data-forum-embed]') as HTMLElement
        );
        expect(iframe?.getAttribute('referrerpolicy')).toBe('no-referrer');
    });

    it.each([
        [
            'youtube',
            'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation',
        ],
        [
            'vimeo',
            'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation',
        ],
        ['twitch', 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox'],
        ['reddit', 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox'],
        ['x', 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox'],
        ['bluesky', 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox'],
        [
            'instagram',
            'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox',
        ],
        ['tiktok', 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox'],
    ])('give the %s player the sandbox its service asked for', (service, sandbox) => {
        const html = CARD.replace(
            'data-embed-service="youtube"',
            `data-embed-service="${service}"`
        ).replace(/data-embed-sandbox="[^"]*"/, `data-embed-sandbox="${sandbox}"`);
        const element = root(html);

        const iframe = loadEmbed(
            element.querySelector<HTMLElement>('[data-forum-embed]') as HTMLElement
        );

        expect(iframe?.getAttribute('sandbox')).toBe(sandbox);
    });

    it('drop every sandbox token a player may not get, and stay strict without any', () => {
        expect(
            sandboxOf(
                'allow-scripts allow-top-navigation allow-forms  allow-modals allow-scripts allow-downloads allow-same-origin'
            )
        ).toBe('allow-scripts allow-same-origin');
        expect(sandboxOf('allow-top-navigation-by-user-activation')).toBe('');

        const element = root(CARD.replace(/ data-embed-sandbox="[^"]*"/, ''));
        const iframe = loadEmbed(
            element.querySelector<HTMLElement>('[data-forum-embed]') as HTMLElement
        );

        expect(iframe?.hasAttribute('sandbox')).toBe(true);
        expect(iframe?.getAttribute('sandbox')).toBe('');
    });

    it('load each player once', () => {
        const element = root();
        const figure = element.querySelector<HTMLElement>('[data-forum-embed]') as HTMLElement;

        expect(loadEmbed(figure)).not.toBeNull();
        expect(loadEmbed(figure)).toBeNull();
        expect(element.querySelectorAll('iframe')).toHaveLength(1);
    });

    it('leave cards without a player alone', () => {
        const element = root(
            '<figure data-forum-embed data-embed-service="instagram"><div class="forum-embed__card"></div></figure>'
        );
        bindEmbedButtons(element);
        loadConsentedEmbeds(element);

        expect(element.querySelector('iframe')).toBeNull();
    });
});
