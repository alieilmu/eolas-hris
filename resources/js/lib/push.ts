import { isIos, isStandalone } from '@/lib/pwa';

/**
 * Notifikasi push (Web Push): this phone / browser subscribes at its push service with the
 * server's key and tells the server where to send. The service worker shows what arrives.
 * iPhone: only in the app installed on the home screen (iOS 16.4+).
 */

export type PushState = 'unsupported' | 'install-first' | 'denied' | 'off' | 'on';

const URL_BASE = '/account/notifications/subscription';

function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function send(method: 'POST' | 'DELETE', body: unknown, keepalive = false): Promise<Response> {
    return fetch(URL_BASE, {
        method,
        keepalive,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(body),
    });
}

export function pushSupported(): boolean {
    return window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

async function registration(): Promise<ServiceWorkerRegistration | null> {
    if (!pushSupported()) return null;
    const existing = await navigator.serviceWorker.getRegistration();
    if (!existing) await navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    // ready never settles without a worker: give up after a while.
    return Promise.race([navigator.serviceWorker.ready, new Promise<null>((r) => setTimeout(() => r(null), 8000))]);
}

export async function currentSubscription(): Promise<PushSubscription | null> {
    const reg = await registration();
    return reg ? reg.pushManager.getSubscription() : null;
}

export async function pushState(): Promise<PushState> {
    if (isIos() && !isStandalone()) return 'install-first';
    if (!pushSupported()) return 'unsupported';
    if (Notification.permission === 'denied') return 'denied';
    return Notification.permission === 'granted' && (await currentSubscription()) ? 'on' : 'off';
}

function keyBytes(key: string): Uint8Array<ArrayBuffer> {
    const b64 = (key + '='.repeat((4 - (key.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(b64);
    const out = new Uint8Array(new ArrayBuffer(raw.length));
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}

function sameKey(sub: PushSubscription, key: string): boolean {
    const current = sub.options.applicationServerKey;
    if (!current) return false;
    const a = new Uint8Array(current);
    const b = keyBytes(key);
    return a.length === b.length && a.every((v, i) => v === b[i]);
}

async function register(sub: PushSubscription, name: string): Promise<void> {
    const json = sub.toJSON();
    const res = await send('POST', { endpoint: json.endpoint, keys: json.keys, name });
    if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error((data.errors && (Object.values(data.errors)[0] as string[])[0]) || data.message || 'Server menolak langganan notifikasi.');
    }
}

/** Asks the phone (it must follow a tap), subscribes, and registers this phone with the server. */
export async function enablePush(key: string, name: string): Promise<void> {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error(permission === 'denied' ? 'Notifikasi diblokir di pengaturan HP / browser.' : 'Izin notifikasi tidak diberikan.');
    const reg = await registration();
    if (!reg) throw new Error('Service worker aplikasi belum aktif. Muat ulang halaman lalu coba lagi.');
    let sub = await reg.pushManager.getSubscription();
    if (sub && !sameKey(sub, key)) {
        await sub.unsubscribe();
        sub = null;
    }
    sub ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) });
    await register(sub, name);
}

export async function disablePush(): Promise<void> {
    const sub = await currentSubscription();
    if (!sub) return;
    await send('DELETE', { endpoint: sub.endpoint }).catch(() => undefined);
    await sub.unsubscribe().catch(() => undefined);
}

/** Once per app session: tells the server again where this phone receives (the push service may change it). */
export async function syncPush(key: string | null, name: string): Promise<void> {
    try {
        if (!key || sessionStorage.getItem('eolas.push-sync') === key) return;
        if (!pushSupported() || Notification.permission !== 'granted') return;
        const sub = await currentSubscription();
        if (!sub) return;
        if (!sameKey(sub, key)) {
            // The server's key changed (a new installation): subscribe again with the new one.
            await sub.unsubscribe();
            const reg = await registration();
            if (!reg) return;
            await register(await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) }), name);
        } else {
            await register(sub, name);
        }
        sessionStorage.setItem('eolas.push-sync', key);
    } catch {
        /* the bell still has everything */
    }
}

/** Signing out: this phone stops receiving the account's notifications. */
export async function forgetPush(): Promise<void> {
    try {
        sessionStorage.removeItem('eolas.push-sync');
        const reg = pushSupported() ? await navigator.serviceWorker.getRegistration() : undefined;
        const sub = reg ? await reg.pushManager.getSubscription() : null;
        if (!sub) return;
        void send('DELETE', { endpoint: sub.endpoint }, true).catch(() => undefined);
        await sub.unsubscribe();
    } catch {
        /* nothing to undo */
    }
}

/** The endpoint's sha256, to tell which registered device is this one. */
export async function endpointHash(sub: PushSubscription | null): Promise<string | null> {
    if (!sub || !crypto.subtle) return null;
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(sub.endpoint));
    return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
}
