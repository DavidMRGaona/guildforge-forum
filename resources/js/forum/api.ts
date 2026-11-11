import { csrfHeaders } from '@/utils/csrf';

export interface JsonAnswer<T> {
    ok: boolean;
    status: number;
    data: T | null;
    /** The server's message, or a field's first error, when it refused */
    message: string | null;
    errors: Record<string, string[]>;
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

    return {
        ok: response.ok,
        status: response.status,
        data: response.ok ? (payload as T) : null,
        message: typeof record.message === 'string' ? record.message : null,
        errors,
    };
}

/** Where a record's page asks for its conversation (SubjectConversationController) */
export function subjectConversationUrl(subjectType: string, subjectId: string): string {
    return `/foro/fichas/${encodeURIComponent(subjectType)}/${encodeURIComponent(subjectId)}`;
}
