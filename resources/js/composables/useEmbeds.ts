import { hasCookieConsent } from '@/contracts/cookieConsent';
import { nextTick, onMounted, watch, watchEffect, type Ref } from 'vue';

/**
 * Embedded posts of other services in a rendered post. The server renders the forum's own
 * card (EmbedNode) and keeps the service's player in data attributes; nothing of the service
 * loads until the reader asks for it («Cargar contenido de X») or already consented to the
 * service's cookie category. Without a cookie consent module there is no consent, so it always
 * takes the click. The player goes in a frame sandboxed with only what its service needs
 * (EmbedPlayer), without referrer (or with the site's
 * origin only, for players that refuse to load without one); no script of the service ever
 * runs on the forum's page.
 */

/**
 * The only sandbox tokens a player may get (EmbedPlayer::ALLOWED_SANDBOX): each service's
 * player says which of them it needs, and anything else in the attribute is dropped. Never
 * top navigation, forms, modals or downloads.
 */
const ALLOWED_SANDBOX = new Set([
    'allow-scripts',
    'allow-same-origin',
    'allow-popups',
    'allow-popups-to-escape-sandbox',
    'allow-presentation',
]);

const REFERRER_POLICIES = new Set(['no-referrer', 'strict-origin']);

const ASPECTS = new Set(['video', 'portrait', 'post']);

/** Loads the player of an embed card; false when it has none or it is already loaded */
export function loadEmbed(figure: HTMLElement): HTMLIFrameElement | null {
    const src = figure.dataset['embedSrc'] ?? '';
    if (figure.dataset['embedLoaded'] === 'true' || !isHttps(src)) {
        return null;
    }

    const aspect = figure.dataset['embedAspect'] ?? '';
    const referrer = figure.dataset['embedReferrer'] ?? '';
    const frame = document.createElement('div');
    frame.className = `forum-embed__frame forum-embed__frame--${ASPECTS.has(aspect) ? aspect : 'video'}`;

    const iframe = document.createElement('iframe');
    iframe.src = src;
    iframe.title = figure.dataset['embedTitle'] ?? '';
    // An empty sandbox (nothing valid asked for) is the strictest one: the player stays inert
    iframe.setAttribute('sandbox', sandboxOf(figure.dataset['embedSandbox'] ?? ''));
    iframe.setAttribute(
        'referrerpolicy',
        REFERRER_POLICIES.has(referrer) ? referrer : 'no-referrer'
    );
    iframe.setAttribute('allow', 'encrypted-media; picture-in-picture; fullscreen');
    iframe.setAttribute('allowfullscreen', '');
    iframe.setAttribute('loading', 'lazy');
    frame.appendChild(iframe);

    const consent = figure.querySelector('.forum-embed__consent');
    if (consent !== null) {
        consent.replaceWith(frame);
    } else {
        figure.appendChild(frame);
    }
    figure.dataset['embedLoaded'] = 'true';

    return iframe;
}

/** Loads every embed in the root whose service's cookie category the reader consented to */
export function loadConsentedEmbeds(root: HTMLElement): void {
    root.querySelectorAll<HTMLElement>('[data-forum-embed][data-embed-consent]').forEach(
        (figure) => {
            const category = figure.dataset['embedConsent'] ?? '';
            if (category !== '' && hasCookieConsent(category)) {
                loadEmbed(figure);
            }
        }
    );
}

/** Wires the «Cargar contenido de X» buttons of a root, once */
export function bindEmbedButtons(root: HTMLElement): void {
    if (root.dataset['embedsBound'] === 'true') {
        return;
    }
    root.dataset['embedsBound'] = 'true';

    root.addEventListener('click', (event) => {
        const button = (event.target as Element | null)?.closest('[data-embed-load]');
        const figure = button?.closest<HTMLElement>('[data-forum-embed]');
        if (figure === null || figure === undefined) {
            return;
        }

        event.preventDefault();
        // The reader asked for it: focus goes where the button was
        loadEmbed(figure)?.focus();
    });
}

/**
 * Embeds of a post body rendered with v-html: buttons wired, and players loaded as soon as
 * consent is there (also when it is given later, without reloading).
 */
export function useEmbeds(root: Ref<HTMLElement | null>, html: () => string | null): void {
    const bind = (): void => {
        if (root.value !== null) {
            bindEmbedButtons(root.value);
        }
    };

    onMounted(bind);
    watch(html, () => void nextTick(bind));

    watchEffect(
        () => {
            // Read so the effect follows a new body; consent is followed by hasCookieConsent()
            void html();
            if (root.value !== null) {
                loadConsentedEmbeds(root.value);
            }
        },
        { flush: 'post' }
    );
}

/** The player's tokens that are allowed, each once, in the order given */
export function sandboxOf(value: string): string {
    const tokens = value.split(/\s+/).filter((token) => ALLOWED_SANDBOX.has(token));

    return [...new Set(tokens)].join(' ');
}

function isHttps(value: string): boolean {
    try {
        return new URL(value).protocol === 'https:';
    } catch {
        return false;
    }
}
