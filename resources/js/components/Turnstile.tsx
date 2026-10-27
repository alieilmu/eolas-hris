import { useEffect, useRef } from 'react';

declare global {
    interface Window {
        turnstile?: {
            render: (el: HTMLElement, options: Record<string, unknown>) => string;
            reset: (id?: string) => void;
            remove: (id: string) => void;
        };
    }
}

const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

function load(): Promise<void> {
    if (window.turnstile) return Promise.resolve();
    return new Promise((resolve, reject) => {
        let tag = document.querySelector<HTMLScriptElement>(`script[src="${SCRIPT}"]`);
        if (!tag) {
            tag = document.createElement('script');
            tag.src = SCRIPT;
            tag.async = true;
            document.head.appendChild(tag);
        }
        tag.addEventListener('load', () => resolve());
        tag.addEventListener('error', () => reject());
    });
}

/**
 * Cloudflare Turnstile ("Saya bukan robot"): hands the token to onToken, and an empty one
 * when it expires. `resetKey` changing (e.g. after a refused submit) asks for a new token.
 */
export function Turnstile({ siteKey, onToken, resetKey }: { siteKey: string; onToken: (token: string) => void; resetKey?: unknown }) {
    const box = useRef<HTMLDivElement>(null);
    const widget = useRef<string | null>(null);
    const callback = useRef(onToken);
    callback.current = onToken;

    useEffect(() => {
        let gone = false;
        load()
            .then(() => {
                if (gone || !box.current || !window.turnstile) return;
                widget.current = window.turnstile.render(box.current, {
                    sitekey: siteKey,
                    language: 'id',
                    callback: (t: string) => callback.current(t),
                    'expired-callback': () => callback.current(''),
                    'error-callback': () => callback.current(''),
                });
            })
            .catch(() => undefined);
        return () => {
            gone = true;
            if (widget.current && window.turnstile) window.turnstile.remove(widget.current);
            widget.current = null;
        };
    }, [siteKey]);

    useEffect(() => {
        if (resetKey === undefined || !widget.current || !window.turnstile) return;
        window.turnstile.reset(widget.current);
        callback.current('');
    }, [resetKey]);

    return <div ref={box} className="min-h-[65px]" />;
}
