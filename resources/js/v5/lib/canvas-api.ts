import { csrfToken } from '@/lib/csrf';

type CanvasRequestOptions = {
    method: 'GET' | 'POST' | 'PATCH' | 'DELETE';
    body?: unknown;
};

/**
 * Shared JSON fetch for the canvas endpoints: same-origin credentials,
 * CSRF header, and a 30 second timeout on every request.
 */
export function canvasRequest(url: string, { method, body }: CanvasRequestOptions): Promise<Response> {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
        signal: AbortSignal.timeout(30_000),
    });
}
