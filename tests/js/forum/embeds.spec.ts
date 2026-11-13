import { afterEach, describe, expect, it } from 'vitest';
import {
    notifyCookieConsentChanged,
    registerCookieConsentProvider,
} from '@/contracts/cookieConsent';
import {
    bindEmbedButtons,
    loadConsentedEmbeds,
    heightFrom,
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
        // Fullscreen comes from `allow` alone: with `allowfullscreen` too, browsers warn
        expect(iframe?.getAttribute('allow')).toContain('fullscreen');
        expect(iframe?.hasAttribute('allowfullscreen')).toBe(false);
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

    it.each([
        ['reddit', { type: 'resize.embed', data: 240 }, 240],
        ['reddit', '{"type":"resize.embed","data":240.4}', 241],
        ['instagram', { type: 'MEASURE', details: { height: 782 } }, 782],
        ['bluesky', { height: 671.625, id: null }, 672],
        [
            'x',
            {
                'twttr.embed': {
                    method: 'twttr.private.resize',
                    params: [{ height: 300, width: 550 }],
                },
            },
            300,
        ],
    ])('read the height the %s frame posts', (service, data, height) => {
        expect(heightFrom(service, data)).toBe(height);
    });

    it('read no height from anything else, and keep it within bounds', () => {
        expect(heightFrom('reddit', { type: 'MEASURE', details: { height: 500 } })).toBeNull();
        expect(heightFrom('youtube', { height: 500 })).toBeNull();
        expect(
            heightFrom('x', { 'twttr.embed': { method: 'twttr.private.initialized' } })
        ).toBeNull();
        expect(heightFrom('bluesky', { height: '500' })).toBeNull();
        expect(heightFrom('bluesky', 'not json')).toBeNull();
        expect(heightFrom('bluesky', { height: Number.POSITIVE_INFINITY })).toBeNull();
        expect(heightFrom('bluesky', { height: 5 })).toBe(80);
        expect(heightFrom('bluesky', { height: 999999 })).toBe(2400);
    });

    it('size a frame only from its own window and origin', () => {
        const element = root(
            CARD.replace('data-embed-service="youtube"', 'data-embed-service="bluesky"').replace(
                'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0',
                'https://embed.bsky.app/embed/did:plc:abc/app.bsky.feed.post/3kxyz'
            )
        );
        const iframe = loadEmbed(
            element.querySelector<HTMLElement>('[data-forum-embed]') as HTMLElement
        ) as HTMLIFrameElement;
        const frame = iframe.parentElement as HTMLElement;
        const post = (origin: string, from: MessageEventSource | null): void => {
            window.dispatchEvent(
                new MessageEvent('message', { data: { height: 400 }, origin, source: from })
            );
        };

        // A frame without a window yet must not match a message without a source
        expect(iframe.contentWindow).toBeNull();
        post('https://embed.bsky.app', null);
        expect(frame.style.height).toBe('');

        const source = { postMessage: () => undefined } as unknown as Window;
        Object.defineProperty(iframe, 'contentWindow', { value: source });

        post('https://evil.example', source);
        post('https://embed.bsky.app', window);
        expect(frame.style.height).toBe('');

        post('https://embed.bsky.app', source);
        expect(frame.style.height).toBe('400px');
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
