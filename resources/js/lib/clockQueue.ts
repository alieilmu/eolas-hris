import { useEffect, useState } from 'react';
import type { MobileClockState } from '@/components/time/MobileClockSheet';

/**
 * Absen HP without a signal: the punch (position, selfie, the phone's time of the tap) waits
 * in IndexedDB on the phone and is sent by the app as soon as it is online again. The server
 * corrects the phone's time by how far its clock is off and refuses a punch older than its
 * limit. Also kept here: the employee's Absen HP state (areas, consent), so the offline page
 * can take a punch when the app is opened with no signal at all.
 */

export interface QueuedPunch {
    id: string;
    /** The account it was taken for; another one signed in later does not send it. */
    user: number;
    type: 'in' | 'out';
    latitude: number;
    longitude: number;
    accuracy: number;
    /** The phone's clock at the tap (ms). */
    taken_at: number;
    photo: Blob;
}

/** What the offline page needs: who, and the state of Beranda when it was last opened. */
export interface SavedClock {
    user: number;
    name: string;
    state: MobileClockState;
    saved_at: number;
}

export interface Rejected {
    id: string;
    type: 'in' | 'out';
    taken_at: number;
    message: string;
}

const DB = 'eolas-app';
const QUEUE = 'clock-queue';
const META = 'meta';

function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB, 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(QUEUE)) db.createObjectStore(QUEUE, { keyPath: 'id' });
            if (!db.objectStoreNames.contains(META)) db.createObjectStore(META);
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function run<T>(store: string, mode: IDBTransactionMode, work: (s: IDBObjectStore) => IDBRequest<T> | void): Promise<T | undefined> {
    const db = await open();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, mode);
        const req = work(tx.objectStore(store));
        tx.oncomplete = () => {
            db.close();
            resolve(req ? req.result : undefined);
        };
        tx.onerror = () => {
            db.close();
            reject(tx.error);
        };
    });
}

const listeners = new Set<() => void>();
const changed = () => listeners.forEach((fn) => fn());

export const uuid = (): string =>
    crypto.randomUUID?.() ??
    '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (Number(c) ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (Number(c) / 4)))).toString(16));

export async function enqueue(punch: QueuedPunch): Promise<void> {
    await run(QUEUE, 'readwrite', (s) => s.put(punch));
    changed();
}

export async function queued(user?: number): Promise<QueuedPunch[]> {
    try {
        const all = ((await run<QueuedPunch[]>(QUEUE, 'readonly', (s) => s.getAll())) ?? []).sort((a, b) => a.taken_at - b.taken_at);
        return user === undefined ? all : all.filter((p) => p.user === user);
    } catch {
        return [];
    }
}

async function remove(id: string): Promise<void> {
    await run(QUEUE, 'readwrite', (s) => s.delete(id));
}

export async function rejected(): Promise<Rejected[]> {
    try {
        return ((await run<Rejected[]>(META, 'readonly', (s) => s.get('rejected'))) ?? []) as Rejected[];
    } catch {
        return [];
    }
}

export async function clearRejected(): Promise<void> {
    await run(META, 'readwrite', (s) => s.delete('rejected'));
    changed();
}

async function addRejected(r: Rejected): Promise<void> {
    const list = [...(await rejected()), r].slice(-10);
    await run(META, 'readwrite', (s) => s.put(list, 'rejected'));
}

export async function saveClock(saved: SavedClock): Promise<void> {
    try {
        await run(META, 'readwrite', (s) => s.put(saved, 'clock'));
    } catch {
        /* private mode: the offline page simply cannot take a punch */
    }
}

export async function savedClock(): Promise<SavedClock | null> {
    try {
        return ((await run<SavedClock>(META, 'readonly', (s) => s.get('clock'))) as SavedClock | undefined) ?? null;
    } catch {
        return null;
    }
}

/** Signing out: the saved state goes; punches not sent yet stay for their own account. */
export async function forgetClock(): Promise<void> {
    try {
        await run(META, 'readwrite', (s) => s.delete('clock'));
    } catch {
        /* nothing kept */
    }
}

function xsrf(): string {
    const hit = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return hit ? decodeURIComponent(hit.slice('XSRF-TOKEN='.length)) : '';
}

let flushing: Promise<number> | null = null;

/**
 * Sends the account's waiting punches, oldest first. Taken (or already there): removed. Refused
 * by the server (too old, outside the area…): removed and kept as a message for the employee.
 * No connection, signed out or app locked: they stay and are tried again later. Returns how
 * many were taken.
 */
export function flush(user: number): Promise<number> {
    flushing ??= (async () => {
        let sent = 0;
        try {
            for (const p of await queued(user)) {
                const body = new FormData();
                body.append('id', p.id);
                body.append('user', String(p.user));
                body.append('type', p.type);
                body.append('latitude', String(p.latitude));
                body.append('longitude', String(p.longitude));
                body.append('accuracy', String(Math.round(p.accuracy)));
                body.append('taken_at', new Date(p.taken_at).toISOString());
                body.append('sent_at', new Date().toISOString());
                body.append('offline', '1');
                body.append('photo', new File([p.photo], 'selfie.jpg', { type: p.photo.type || 'image/jpeg' }));
                let res: Response;
                try {
                    res = await fetch('/self-service/clock', {
                        method: 'POST',
                        body,
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
                    });
                } catch {
                    break; // still no connection
                }
                if (res.ok) {
                    await remove(p.id);
                    sent++;
                } else if (res.status === 422 || res.status === 409) {
                    const data = await res.json().catch(() => ({}));
                    const message = (Object.values(data.errors ?? {}) as string[][])[0]?.[0] ?? data.message ?? 'Absen ditolak server.';
                    await remove(p.id);
                    await addRejected({ id: p.id, type: p.type, taken_at: p.taken_at, message });
                } else {
                    break; // signed out, locked, too many at once, server busy: later
                }
            }
        } finally {
            flushing = null;
            changed();
        }
        return sent;
    })();
    return flushing;
}

/** The account's waiting punches and refused ones, kept up to date. */
export function useClockQueue(user: number | null | undefined): { waiting: QueuedPunch[]; refused: Rejected[] } {
    const [waiting, setWaiting] = useState<QueuedPunch[]>([]);
    const [refused, setRefused] = useState<Rejected[]>([]);
    useEffect(() => {
        if (!user || typeof indexedDB === 'undefined') return;
        let alive = true;
        const load = () => {
            void queued(user).then((q) => alive && setWaiting(q));
            void rejected().then((r) => alive && setRefused(r));
        };
        load();
        listeners.add(load);
        return () => {
            alive = false;
            listeners.delete(load);
        };
    }, [user]);
    return { waiting, refused };
}

export const clockTime = (ms: number) =>
    new Date(ms).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) +
    (new Date(ms).toDateString() === new Date().toDateString() ? '' : ` (${new Date(ms).toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })})`);
