/** Laravel sets XSRF-TOKEN as a cookie; sending it back satisfies CSRF protection for fetch requests. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function post(url: string, body: BodyInit, headers: Record<string, string> = {}): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            ...headers,
        },
    });
}

/** Throws with the server's message when the request failed. */
async function failed(res: Response): Promise<never> {
    const data = await res.json().catch(() => ({}));
    if (res.status === 419) throw new Error('Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    if (res.status === 403) throw new Error('Anda tidak memiliki akses untuk tindakan ini.');
    const firstError = data.errors ? (Object.values(data.errors)[0] as string[] | undefined)?.[0] : undefined;
    throw new Error(firstError || data.message || 'Terjadi kesalahan di server.');
}

/** POST multipart data to a JSON endpoint (file uploads). Throws with the server's message on failure. */
export async function postForm<T>(url: string, body: FormData): Promise<T> {
    const res = await post(url, body);
    if (!res.ok) return failed(res);
    return (await res.json()) as T;
}

/** POST JSON to a JSON endpoint. Throws with the server's message on failure. */
export async function postJson<T>(url: string, body: unknown): Promise<T> {
    const res = await post(url, JSON.stringify(body), { 'Content-Type': 'application/json' });
    if (!res.ok) return failed(res);
    return (await res.json()) as T;
}

/** DELETE on a JSON endpoint. Throws with the server's message on failure. */
export async function deleteJson<T>(url: string): Promise<T> {
    const res = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
    });
    if (!res.ok) return failed(res);
    return (await res.json()) as T;
}

/** POST JSON to an endpoint that answers with a file, and save that file. */
export async function downloadPost(url: string, body: unknown, filename: string): Promise<void> {
    const res = await post(url, JSON.stringify(body), { 'Content-Type': 'application/json' });
    if (!res.ok) return failed(res);
    const link = document.createElement('a');
    link.href = URL.createObjectURL(await res.blob());
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}
