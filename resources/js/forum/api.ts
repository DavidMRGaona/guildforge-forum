import { csrfHeaders } from '@/utils/csrf';
import type { ForumMergedInto } from '../types/forum';

export interface JsonAnswer<T> {
    ok: boolean;
    status: number;
    data: T | null;
    /** The server's message, or a field's first error, when it refused */
    message: string | null;
    errors: Record<string, string[]>;
    /** Where the discussion went, when it answered 410 because it was merged into another */
    mergedInto?: ForumMergedInto | null;
}

/**
 * A JSON request to the forum's endpoints, with the session's CSRF token. It never throws: a
 * network failure answers status 0.
 */
export async function sendJson<T>(
    url: string,
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    body?: unknown
): Promise<JsonAnswer<T>> {
    let response: Response;
    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...csrfHeaders(),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch {
        return { ok: false, status: 0, data: null, message: null, errors: {} };
    }

    return readAnswer<T>(response);
}

/**
 * A multipart request (a form with a file) to the forum's endpoints, answered in JSON. It
 * never throws: a network failure answers status 0.
 */
export async function sendForm<T>(url: string, form: FormData): Promise<JsonAnswer<T>> {
    let response: Response;
    try {
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...csrfHeaders(),
            },
            body: form,
        });
    } catch {
        return { ok: false, status: 0, data: null, message: null, errors: {} };
    }

    return readAnswer<T>(response);
}

async function readAnswer<T>(response: Response): Promise<JsonAnswer<T>> {
    let payload: unknown;
    try {
        payload = await response.json();
    } catch {
        payload = null;
    }

    const record =
        typeof payload === 'object' && payload !== null ? (payload as Record<string, unknown>) : {};
    const errors =
        typeof record.errors === 'object' && record.errors !== null
            ? (record.errors as Record<string, string[]>)
            : {};

    const mergedInto = mergedTarget(response.status, record);

    return {
        ok: response.ok,
        status: response.status,
        data: response.ok ? (payload as T) : null,
        message: typeof record.message === 'string' ? record.message : null,
        errors,
        mergedInto,
    };
}

/**
 * Where the discussion went, when the server answered 410 because it was merged into another
 * (MergedDiscussionAnswer); null for any other answer.
 */
export function mergedTarget(status: number, payload: unknown): ForumMergedInto | null {
    if (status !== 410 || typeof payload !== 'object' || payload === null) {
        return null;
    }
    const merged = (payload as Record<string, unknown>).mergedInto;

    return typeof merged === 'object' &&
        merged !== null &&
        typeof (merged as Record<string, unknown>).title === 'string' &&
        typeof (merged as Record<string, unknown>).url === 'string'
        ? (merged as ForumMergedInto)
        : null;
}

/** Where a record's page asks for its conversation (SubjectConversationController) */
export function subjectConversationUrl(subjectType: string, subjectId: string): string {
    return `/foro/fichas/${encodeURIComponent(subjectType)}/${encodeURIComponent(subjectId)}`;
}

/** Where a hidden roll is revealed, for good (RollController::reveal) */
export function revealRollUrl(rollId: string): string {
    return `/foro/tiradas/${encodeURIComponent(rollId)}/revelar`;
}

/** The posts of a discussion after the number (LiveUpdatesController::posts) */
export function discussionUpdatesUrl(
    discussionId: string,
    after: number,
    changed: number[] = []
): string {
    const url = `/foro/temas/${encodeURIComponent(discussionId)}/novedades?despues=${after}`;

    // Posts the page holds and was told changed, which come again as the reader sees them now
    return changed.length === 0 ? url : `${url}&cambiados=${changed.join(',')}`;
}

/** The entries of a conversation after the given one, and those it was told changed */
export function conversationUpdatesUrl(
    conversationId: string,
    after: number,
    changed: number[] = []
): string {
    const url = `/foro/conversaciones/${encodeURIComponent(conversationId)}/novedades?despues=${after}`;

    return changed.length === 0 ? url : `${url}&cambiados=${changed.join(',')}`;
}

/** What changed in the forum for the signed-in person, and the last post of the discussions given */
export function forumUpdatesUrl(discussionIds: string[] = []): string {
    return discussionIds.length === 0
        ? '/foro/novedades'
        : `/foro/novedades?temas=${discussionIds.map(encodeURIComponent).join(',')}`;
}
