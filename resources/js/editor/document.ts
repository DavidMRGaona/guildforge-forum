import type { JSONContent } from '@tiptap/core';
import { MAX_EXPRESSION_LENGTH, MAX_REASON_LENGTH } from './rollText';

/**
 * The editor's side of the post document schema (src/Application/Content on the server).
 * The server checks every document again and refuses, never repairs, what does not fit:
 * these mirror its rules so the editor only ever produces documents it accepts.
 */

/** Stored path of an image uploaded to the forum (ImageNode::PATH_PATTERN) */
export const FORUM_IMAGE_PATTERN =
    /^forum\/posts\/\d{4}\/\d{2}\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp|gif)$/;

/** Stored path of the forum's copy of an image from another site (ImageNode::EXTERNAL_PATH_PATTERN) */
export const EXTERNAL_IMAGE_PATTERN =
    /^forum\/external\/\d{4}\/\d{2}\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp|gif)$/;

/** The origin of an image uploaded to the forum (ImageNode::ORIGIN_UPLOAD) */
export const IMAGE_ORIGIN_UPLOAD = 'upload';

/** The origin of an image from another site, shown from the forum's copy (ImageNode::ORIGIN_EXTERNAL) */
export const IMAGE_ORIGIN_EXTERNAL = 'external';

/**
 * An address images may be fetched from, as the server reads it (RemoteUrl): https on the
 * default port, a host name with a top-level domain, plain URL characters and no fragment.
 */
const REMOTE_URL_PATTERN =
    /^https:\/\/((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?)(?::443)?(\/[A-Za-z0-9\-._~:/?[\]@!$&'()*+,;=%]*)?$/;

const MAX_REMOTE_URL_LENGTH = 2048;

/** The host of an address images may be fetched from, or null when it is not one */
export function remoteImageHost(address: string): string | null {
    if (address.length > MAX_REMOTE_URL_LENGTH) {
        return null;
    }

    return REMOTE_URL_PATTERN.exec(address)?.[1] ?? null;
}

/** Whether the address is https on one of the sites (each with its subdomains) */
export function isAllowedImageAddress(address: string, hosts: readonly string[]): boolean {
    const host = remoteImageHost(address);

    return host !== null && hosts.some((site) => host === site || host.endsWith(`.${site}`));
}

/** Whether the address names an image file (so pasting it means the image, not a link) */
export function looksLikeImageFile(address: string): boolean {
    return /\.(png|jpe?g|gif|webp)(\?[^#]*)?$/i.test(address);
}

/** Image formats the forum may allow (PostImageType); the settings say which it does */
export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'] as const;

/** Heading levels a post may use: level 1 is the discussion title */
export const HEADING_LEVELS = [2, 3, 4] as const;

export type HeadingLevel = (typeof HEADING_LEVELS)[number];

/** Limits of DocumentLimits on the server (images per post default; the settings may change it) */
export const MAX_IMAGES = 10;
export const MAX_MENTIONS = 20;
export const MAX_QUOTES = 10;
export { MAX_ROLLS } from './rollText';

const MAX_LINK_LENGTH = 2048;

/** Code block languages the server accepts (CodeBlockNode::LANGUAGE_PATTERN) */
const CODE_LANGUAGE_PATTERN = /^[a-z0-9][a-z0-9+#.-]{0,29}$/;

/** A code block's language in the form the server accepts, or null */
export function normalizeCodeLanguage(language: string | null | undefined): string | null {
    const lowered = (language ?? '').toLowerCase();

    return CODE_LANGUAGE_PATTERN.test(lowered) ? lowered : null;
}

/**
 * An absolute http or https address with a host and no credentials, or a mailto address
 * (LinkMark::isAllowed). Nothing is trimmed: spaces make it invalid.
 */
export function isAllowedHref(href: string): boolean {
    // eslint-disable-next-line no-control-regex -- control characters are what it refuses
    if (href === '' || href.length > MAX_LINK_LENGTH || /[\s\u0000-\u001f\u007f]/u.test(href)) {
        return false;
    }

    if (/^mailto:/i.test(href)) {
        return /^mailto:[^/]*@/i.test(href);
    }

    if (!/^https?:\/\//i.test(href)) {
        return false;
    }

    try {
        const url = new URL(href);

        return url.hostname !== '' && url.username === '' && url.password === '';
    } catch {
        return false;
    }
}

/**
 * What someone typed as a link, as an address the forum accepts: «ejemplo.com» becomes
 * «https://ejemplo.com»; anything else that is not allowed gives null.
 */
export function normalizeHref(input: string): string | null {
    const href = input.trim();
    if (isAllowedHref(href)) {
        return href;
    }

    const withScheme = /^[a-z][a-z0-9+.-]*:/i.test(href) ? href : `https://${href}`;

    return isAllowedHref(withScheme) ? withScheme : null;
}

/** The nodes of the schema that hold text or something to see, so a post made of them is not empty */
const SUBSTANTIAL_NODES = new Set(['image', 'mention', 'roll']);

/** Whether a document has nothing to publish: no text but spaces, no image, no mention and no roll */
export function isDocumentEmpty(document: JSONContent | null | undefined): boolean {
    if (document === null || document === undefined) {
        return true;
    }

    const visit = (node: JSONContent): boolean =>
        (node.type === 'text' && /\S/u.test(node.text ?? '')) ||
        SUBSTANTIAL_NODES.has(node.type ?? '') ||
        (node.content ?? []).some(visit);

    return !visit(document);
}

/**
 * Pasted or converted content made to fit the schema: images from outside the forum and links
 * the forum does not accept are dropped (the link text stays), and headings take the nearest
 * allowed level.
 */
export function sanitizeContent(node: JSONContent): JSONContent | null {
    if (node.type === 'image') {
        return sanitizeImage(node);
    }

    // A roll that is pasted or converted is a new one: its expression and reason, never an id or a result
    if (node.type === 'roll') {
        const expression: unknown = node.attrs?.['expression'];
        const reason: unknown = node.attrs?.['reason'];

        return {
            type: 'roll',
            attrs: {
                expression:
                    typeof expression === 'string'
                        ? expression.slice(0, MAX_EXPRESSION_LENGTH)
                        : '',
                reason:
                    typeof reason === 'string' && reason !== ''
                        ? reason.slice(0, MAX_REASON_LENGTH)
                        : null,
                rollId: null,
            },
        };
    }

    const sanitized: JSONContent = { ...node };

    if (node.marks !== undefined) {
        sanitized.marks = node.marks.filter((mark) => {
            const href: unknown = mark.attrs?.['href'];

            return mark.type !== 'link' || (typeof href === 'string' && isAllowedHref(href));
        });
        if (sanitized.marks.length === 0) {
            delete sanitized.marks;
        }
    }

    if (node.type === 'heading') {
        const level = Number(node.attrs?.['level'] ?? HEADING_LEVELS[0]);
        sanitized.attrs = {
            ...node.attrs,
            level: Math.min(
                Math.max(level, HEADING_LEVELS[0]),
                HEADING_LEVELS[HEADING_LEVELS.length - 1]
            ),
        };
    }

    if (node.type === 'codeBlock') {
        const language: unknown = node.attrs?.['language'];
        sanitized.attrs = {
            language: normalizeCodeLanguage(typeof language === 'string' ? language : null),
        };
    }

    if (node.content !== undefined) {
        sanitized.content = node.content
            .map(sanitizeContent)
            .filter((child): child is JSONContent => child !== null);
    }

    return sanitized;
}

/**
 * An image as the server takes it: an upload of the forum, or an image from another site with
 * its https address and the forum's copy. Anything else (an image of another site pasted as
 * is, which readers' browsers would ask that site for) is dropped.
 */
function sanitizeImage(node: JSONContent): JSONContent | null {
    const src: unknown = node.attrs?.['src'];
    const path: unknown = node.attrs?.['path'];
    const alt: unknown = node.attrs?.['alt'];
    const description = typeof alt === 'string' && alt !== '' ? alt : null;

    if (node.attrs?.['origin'] === IMAGE_ORIGIN_EXTERNAL) {
        return typeof src === 'string' &&
            remoteImageHost(src) !== null &&
            typeof path === 'string' &&
            EXTERNAL_IMAGE_PATTERN.test(path)
            ? {
                  type: 'image',
                  attrs: { origin: IMAGE_ORIGIN_EXTERNAL, src, path, alt: description },
              }
            : null;
    }

    return typeof src === 'string' && FORUM_IMAGE_PATTERN.test(src)
        ? {
              type: 'image',
              attrs: { origin: IMAGE_ORIGIN_UPLOAD, src, path: null, alt: description },
          }
        : null;
}

/**
 * The URL to show a stored image (an upload or the forum's copy of an image from another site)
 * while writing. The server renders posts with its own delivery URL; this one only has to show
 * the same file.
 */
export function storedImageUrl(
    path: string,
    buildUrl: (publicId: string) => string | null
): string | null {
    return FORUM_IMAGE_PATTERN.test(path) || EXTERNAL_IMAGE_PATTERN.test(path)
        ? buildUrl(path.replace(/\.[a-z]+$/, ''))
        : null;
}
