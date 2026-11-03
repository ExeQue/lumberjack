/** Laravel's XSRF-TOKEN cookie, for fetch requests that change something. */
export function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** A JSON request with the session's CSRF token; the response's JSON, or throws with its status. */
export async function sendJson<T>(
    url: string,
    method: 'POST' | 'PUT',
    body: unknown = {},
): Promise<{ status: number; json: T | null }> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: JSON.stringify(body),
    });

    return {
        status: response.status,
        json: (await response.json().catch(() => null)) as T | null,
    };
}
