import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, Loader2, Save } from 'lucide-react';

/** A form with unsaved changes: what it is, and how to save or drop them. */
export interface DirtyEntry {
    label: string;
    /** Resolves true when saved, false when the server refused (errors are shown in the form). */
    save: () => Promise<boolean>;
    discard: () => void;
}

interface GuardApi {
    register: (key: string, entry: DirtyEntry | null) => void;
    /** Runs `proceed` right away when nothing is unsaved, otherwise after the user decides. */
    guard: (proceed: () => void) => void;
}

const GuardContext = createContext<GuardApi | null>(null);

/**
 * Registers a form while it has unsaved changes. The latest save/discard are
 * always used, so callers can pass fresh closures on every render.
 */
export function useDirtyForm(key: string, dirty: boolean, entry: DirtyEntry) {
    const api = useContext(GuardContext);
    const latest = useRef(entry);
    latest.current = entry;

    useEffect(() => {
        if (!api) return;
        api.register(key, dirty ? { label: entry.label, save: () => latest.current.save(), discard: () => latest.current.discard() } : null);
        return () => api.register(key, null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [api, key, dirty, entry.label]);
}

export function useGuard() {
    return useContext(GuardContext)?.guard ?? ((proceed: () => void) => proceed());
}

/**
 * Keeps unsaved master-data edits from being lost: switching master type,
 * leaving for another module, or closing the tab first asks to save or keep editing.
 */
export function DirtyGuardProvider({ children }: { children: ReactNode }) {
    const entries = useRef(new Map<string, DirtyEntry>());
    const [pending, setPending] = useState<(() => void) | null>(null);
    const [pendingLabels, setPendingLabels] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);
    const [saveFailed, setSaveFailed] = useState(false);
    const bypass = useRef(false);

    const register = useCallback((key: string, entry: DirtyEntry | null) => {
        if (entry) entries.current.set(key, entry);
        else entries.current.delete(key);
    }, []);

    const guard = useCallback((proceed: () => void) => {
        if (entries.current.size === 0) {
            proceed();
            return;
        }
        setPendingLabels([...entries.current.values()].map((e) => e.label));
        setSaveFailed(false);
        setPending(() => proceed);
    }, []);

    // Leaving for another page through the app (sidebar, links).
    useEffect(
        () =>
            router.on('before', (event) => {
                const visit = event.detail.visit;
                const leaving = visit.method === 'get' && visit.url.pathname !== window.location.pathname;
                if (!leaving || bypass.current || entries.current.size === 0) return;

                guard(() => {
                    bypass.current = true;
                    router.visit(visit.url.href, { onFinish: () => (bypass.current = false) });
                });
                return false;
            }),
        [guard],
    );

    // Closing or reloading the tab: only the browser's own prompt is possible here.
    useEffect(() => {
        const onUnload = (e: BeforeUnloadEvent) => {
            if (entries.current.size > 0) e.preventDefault();
        };
        window.addEventListener('beforeunload', onUnload);
        return () => window.removeEventListener('beforeunload', onUnload);
    }, []);

    const saveAndGo = async () => {
        setSaving(true);
        let ok = true;
        for (const entry of [...entries.current.values()]) {
            ok = (await entry.save()) && ok;
        }
        setSaving(false);
        if (!ok) {
            // Errors are now shown in the form itself; stay so they can be fixed.
            setSaveFailed(true);
            return;
        }
        const proceed = pending;
        setPending(null);
        proceed?.();
    };

    const discardAndGo = () => {
        [...entries.current.values()].forEach((entry) => entry.discard());
        entries.current.clear();
        const proceed = pending;
        setPending(null);
        proceed?.();
    };

    return (
        <GuardContext.Provider value={{ register, guard }}>
            {children}

            {pending && (
                <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-900/40 p-6 backdrop-blur-sm">
                    <div className="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl">
                        <div className="space-y-3 p-6">
                            <div className="flex items-start gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-50 text-amber-600">
                                    <AlertTriangle size={20} />
                                </div>
                                <div>
                                    <h3 className="font-bold text-slate-800">Ada perubahan yang belum disimpan</h3>
                                    <p className="mt-1 text-sm text-slate-500">
                                        {pendingLabels.join(', ')} masih berisi isian yang belum disimpan. Simpan dulu sebelum pindah, atau lanjutkan
                                        mengedit.
                                    </p>
                                </div>
                            </div>
                            {saveFailed && (
                                <p className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-2.5 text-sm text-rose-600">
                                    Belum bisa disimpan — periksa pesan kesalahan di formulir, lalu coba lagi.
                                </p>
                            )}
                        </div>

                        <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-6 py-4">
                            <button
                                onClick={discardAndGo}
                                disabled={saving}
                                className="text-xs font-bold text-slate-400 hover:text-rose-600 disabled:opacity-50"
                            >
                                Buang perubahan
                            </button>
                            <div className="flex gap-2">
                                <button
                                    onClick={() => setPending(null)}
                                    disabled={saving}
                                    className="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 transition-all hover:bg-slate-100"
                                >
                                    Lanjut Edit
                                </button>
                                {!saveFailed && (
                                    <button
                                        onClick={saveAndGo}
                                        disabled={saving}
                                        className="flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white transition-all hover:bg-brand-700 disabled:opacity-50"
                                    >
                                        {saving ? <Loader2 className="animate-spin" size={16} /> : <Save size={16} />}
                                        Simpan
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </GuardContext.Provider>
    );
}
