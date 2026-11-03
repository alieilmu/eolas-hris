import { useEffect, useState } from 'react';

/**
 * The app on the phone (PWA): the service worker, the install prompt, and WebAuthn for opening
 * the installed app with the phone's fingerprint / face.
 */

/** Opened from the home-screen icon, not in a browser tab. */
export function isStandalone(): boolean {
    return window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

/** iPhone / iPad: no install prompt, only Share → Add to Home Screen. */
export function isIos(): boolean {
    const ua = navigator.userAgent;
    return /iPhone|iPad|iPod/.test(ua) || (ua.includes('Macintosh') && navigator.maxTouchPoints > 1);
}

/** Set once the installed app is opened (signed in or unlocked); gone when the app is closed. */
export const OPEN_KEY = 'eolas.app-open';

export function markOpen(): void {
    try {
        sessionStorage.setItem(OPEN_KEY, '1');
    } catch {
        /* private mode: the lock simply shows again */
    }
}

export function wasOpen(): boolean {
    try {
        return sessionStorage.getItem(OPEN_KEY) === '1';
    } catch {
        return true;
    }
}

interface InstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

let deferred: InstallPromptEvent | null = null;
const listeners = new Set<() => void>();

/** Called once at start: the service worker, and Android's install prompt kept for the button. */
export function startPwa(): void {
    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => undefined));
    }
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferred = event as InstallPromptEvent;
        listeners.forEach((fn) => fn());
    });
    window.addEventListener('appinstalled', () => {
        deferred = null;
        listeners.forEach((fn) => fn());
    });
}

/** Android / desktop Chrome: whether "Pasang aplikasi" can be offered, and the action. */
export function useInstallPrompt(): { available: boolean; install: () => Promise<boolean> } {
    const [available, setAvailable] = useState(!!deferred);
    useEffect(() => {
        const update = () => setAvailable(!!deferred);
        listeners.add(update);
        return () => {
            listeners.delete(update);
        };
    }, []);

    return {
        available,
        install: async () => {
            if (!deferred) return false;
            await deferred.prompt();
            const { outcome } = await deferred.userChoice;
            deferred = null;
            listeners.forEach((fn) => fn());
            return outcome === 'accepted';
        },
    };
}

/** Whether the browser is online, updated as the signal comes and goes. */
export function useOnline(): boolean {
    const [online, setOnline] = useState(navigator.onLine);
    useEffect(() => {
        const on = () => setOnline(true);
        const off = () => setOnline(false);
        window.addEventListener('online', on);
        window.addEventListener('offline', off);
        return () => {
            window.removeEventListener('online', on);
            window.removeEventListener('offline', off);
        };
    }, []);
    return online;
}

// --- WebAuthn -------------------------------------------------------------------

const toBytes = (text: string): ArrayBuffer => {
    const base64 = text.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(text.length / 4) * 4, '=');
    return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0)).buffer;
};

const toText = (bytes: ArrayBuffer): string => btoa(String.fromCharCode(...new Uint8Array(bytes))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

export async function passkeySupported(): Promise<boolean> {
    if (!window.PublicKeyCredential || !window.isSecureContext) return false;
    try {
        return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    } catch {
        return false;
    }
}

type Descriptor = { type: 'public-key'; id: string; transports?: AuthenticatorTransport[] };

/** Registers this phone's fingerprint / face: the options come from the server, the answer goes back to it. */
export async function createPasskey(options: {
    challenge: string;
    rp: PublicKeyCredentialRpEntity;
    user: { id: string; name: string; displayName: string };
    pubKeyCredParams: PublicKeyCredentialParameters[];
    authenticatorSelection: AuthenticatorSelectionCriteria;
    excludeCredentials: Descriptor[];
    attestation: AttestationConveyancePreference;
    timeout: number;
}): Promise<{ id: string; clientDataJSON: string; publicKey: string; algorithm: number }> {
    const credential = (await navigator.credentials.create({
        publicKey: {
            ...options,
            challenge: toBytes(options.challenge),
            user: { ...options.user, id: toBytes(options.user.id) },
            excludeCredentials: options.excludeCredentials.map((c) => ({ ...c, id: toBytes(c.id) })),
        },
    })) as PublicKeyCredential | null;
    if (!credential) throw new Error('Dibatalkan.');
    const response = credential.response as AuthenticatorAttestationResponse;
    const publicKey = response.getPublicKey?.();
    if (!publicKey) throw new Error('Browser ini belum mendukung sidik jari / wajah untuk aplikasi. Perbarui browser, atau pakai PIN.');

    return { id: credential.id, clientDataJSON: toText(response.clientDataJSON), publicKey: toText(publicKey), algorithm: response.getPublicKeyAlgorithm() };
}

/** Asks the phone for the fingerprint / face and returns the signed answer for the server. */
export async function signWithPasskey(options: {
    challenge: string;
    rpId: string;
    allowCredentials: Descriptor[];
    userVerification: UserVerificationRequirement;
    timeout: number;
}): Promise<{ id: string; clientDataJSON: string; authenticatorData: string; signature: string }> {
    const credential = (await navigator.credentials.get({
        publicKey: { ...options, challenge: toBytes(options.challenge), allowCredentials: options.allowCredentials.map((c) => ({ ...c, id: toBytes(c.id) })) },
    })) as PublicKeyCredential | null;
    if (!credential) throw new Error('Dibatalkan.');
    const response = credential.response as AuthenticatorAssertionResponse;

    return {
        id: credential.id,
        clientDataJSON: toText(response.clientDataJSON),
        authenticatorData: toText(response.authenticatorData),
        signature: toText(response.signature),
    };
}

/** A short name for the phone, from the browser. */
export function deviceName(): string {
    const ua = navigator.userAgent;
    if (/iPhone/.test(ua)) return 'iPhone';
    if (/iPad/.test(ua)) return 'iPad';
    const android = ua.match(/Android [\d.]+; ([^;)]+)/);
    if (android) return android[1].replace(/ Build.*/, '').trim();
    if (/Android/.test(ua)) return 'HP Android';
    return 'Perangkat ini';
}
