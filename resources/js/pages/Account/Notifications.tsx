import { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Bell, BellOff, BellRing, Loader2, Save, Send, Share, Smartphone, SquarePlus, Trash2 } from 'lucide-react';
import { postJson } from '@/lib/http';
import { deviceName } from '@/lib/pwa';
import { currentSubscription, disablePush, enablePush, endpointHash, pushState, type PushState } from '@/lib/push';
import { fmtDate } from '@/lib/format';

interface Props {
    /** The server may push (PUSH_ENABLED, OpenSSL). */
    enabled: boolean;
    public_key: string | null;
    preferences: Record<string, boolean>;
    categories: { key: string; label: string; hint: string }[];
    devices: { id: number; name: string | null; endpoint_hash: string; failures: number; created_at: string | null; last_used_at: string | null }[];
}

/** Akun → Notifikasi HP: this phone receiving notifications, which kinds, the phones registered, a test. */
export default function Notifications({ enabled, public_key, preferences, categories, devices }: Props) {
    const { auth } = usePage().props;
    const employee = auth.user?.role === 'employee';
    const [state, setState] = useState<PushState | null>(null);
    const [mine, setMine] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<string | null>(null);
    const [prefs, setPrefs] = useState(preferences);
    const [saving, setSaving] = useState(false);

    const refresh = async () => {
        setState(await pushState());
        setMine(await endpointHash(await currentSubscription().catch(() => null)));
    };

    useEffect(() => {
        void refresh();
    }, []);

    const turnOn = async () => {
        if (!public_key) return;
        setError(null);
        setResult(null);
        setBusy(true);
        try {
            await enablePush(public_key, deviceName());
            router.reload({ only: ['devices'] });
        } catch (e) {
            setError((e as Error).message);
        } finally {
            await refresh();
            setBusy(false);
        }
    };

    const turnOff = async () => {
        setBusy(true);
        await disablePush();
        router.reload({ only: ['devices'] });
        await refresh();
        setBusy(false);
    };

    const test = async () => {
        setError(null);
        setResult(null);
        setBusy(true);
        try {
            const r = await postJson<{ sent: number; total: number }>('/account/notifications/test', {});
            setResult(
                r.total === 0
                    ? 'Belum ada HP yang terdaftar.'
                    : r.sent === r.total
                      ? `Terkirim ke ${r.sent} perangkat. Notifikasi muncul dalam beberapa detik.`
                      : r.sent === 0
                        ? 'Server tidak bisa menghubungi layanan push. Periksa koneksi internet server; notifikasi tetap ada di lonceng aplikasi.'
                        : `Terkirim ke ${r.sent} dari ${r.total} perangkat.`,
            );
            router.reload({ only: ['devices'] });
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    const remove = (id: number) => {
        if (confirm('Hapus perangkat ini? Notifikasi tidak dikirim lagi ke sana.')) router.delete(`/account/notifications/devices/${id}`, { preserveScroll: true, onSuccess: () => void refresh() });
    };

    const save = () => router.put('/account/notifications', { preferences: prefs }, { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) });
    const changed = categories.some((c) => prefs[c.key] !== preferences[c.key]);

    return (
        <div className={employee ? '' : 'flex min-h-0 flex-1 items-start justify-center overflow-y-auto bg-slate-50 p-6'}>
            <Head title="Notifikasi HP" />
            <div className="mx-auto w-full max-w-md space-y-4">
                <div className="rounded-3xl border border-slate-200 border-t-4 border-t-accent-400 bg-white p-5 shadow-sm">
                    <h1 className="flex items-center gap-2 text-lg font-bold text-slate-800">
                        <BellRing size={19} className="text-brand-500" /> Notifikasi HP
                    </h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Notifikasi muncul di HP walau aplikasi sedang ditutup. Semua notifikasi tetap tersimpan di lonceng aplikasi.
                    </p>
                </div>

                <div className="space-y-3 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex items-center justify-between gap-2">
                        <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                            <Smartphone size={16} className="text-brand-500" /> HP / browser ini
                        </h2>
                        {state && enabled && (
                            <span
                                className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${state === 'on' ? 'bg-emerald-50 text-emerald-700' : state === 'denied' ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-500'}`}
                            >
                                {state === 'on' ? 'Aktif' : state === 'denied' ? 'Diblokir' : 'Belum aktif'}
                            </span>
                        )}
                    </div>

                    {!enabled ? (
                        <p className="rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500">
                            Notifikasi push tidak diaktifkan di server ini. Notifikasi tetap tampil di lonceng aplikasi.
                        </p>
                    ) : state === null ? (
                        <p className="flex items-center gap-2 text-xs text-slate-400">
                            <Loader2 size={14} className="animate-spin" /> Memeriksa…
                        </p>
                    ) : state === 'install-first' ? (
                        <div className="space-y-2 rounded-2xl bg-slate-50 p-3 text-xs text-slate-600">
                            <p className="font-bold text-slate-700">Di iPhone, notifikasi hanya untuk aplikasi yang dipasang (iOS 16.4 ke atas):</p>
                            <p className="flex items-center gap-2">
                                <Share size={14} className="shrink-0 text-brand-600" /> 1. Ketuk Bagikan di Safari
                            </p>
                            <p className="flex items-center gap-2">
                                <SquarePlus size={14} className="shrink-0 text-brand-600" /> 2. Pilih Tambahkan ke Layar Utama
                            </p>
                            <p>3. Buka aplikasi dari layar utama, masuk, lalu aktifkan notifikasi di halaman ini.</p>
                        </div>
                    ) : state === 'unsupported' ? (
                        <p className="rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500">Browser ini tidak mendukung notifikasi push. Coba Chrome di Android, atau Safari dari aplikasi yang dipasang di iPhone.</p>
                    ) : state === 'denied' ? (
                        <p className="rounded-xl bg-rose-50 px-3 py-2 text-xs text-rose-700">
                            Notifikasi untuk situs ini diblokir. Buka pengaturan HP / browser → Notifikasi → izinkan untuk aplikasi ini, lalu muat ulang halaman.
                        </p>
                    ) : state === 'on' ? (
                        <div className="flex gap-2">
                            <button
                                onClick={test}
                                disabled={busy}
                                className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                            >
                                {busy ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />} Kirim tes
                            </button>
                            <button
                                onClick={turnOff}
                                disabled={busy}
                                className="flex items-center gap-1.5 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-500 hover:bg-rose-50 hover:text-rose-600 disabled:opacity-50"
                            >
                                <BellOff size={16} /> Matikan
                            </button>
                        </div>
                    ) : (
                        <button
                            onClick={turnOn}
                            disabled={busy || !public_key}
                            className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                        >
                            {busy ? <Loader2 size={16} className="animate-spin" /> : <Bell size={16} />} Aktifkan notifikasi di HP ini
                        </button>
                    )}
                    {result && <p className="rounded-xl bg-emerald-50 px-3 py-2 text-xs text-emerald-700">{result}</p>}
                    {error && <p className="text-xs text-rose-600">{error}</p>}
                </div>

                {enabled && (
                    <div className="space-y-3 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-sm font-bold text-slate-700">Yang dikirim ke HP</h2>
                        <div className="divide-y divide-slate-100">
                            {categories.map((c) => (
                                <label key={c.key} className="flex cursor-pointer items-center gap-3 py-2.5">
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm font-bold text-slate-700">{c.label}</span>
                                        <span className="block text-xs text-slate-400">{c.hint}</span>
                                    </span>
                                    <input
                                        type="checkbox"
                                        role="switch"
                                        checked={prefs[c.key]}
                                        onChange={(e) => setPrefs({ ...prefs, [c.key]: e.target.checked })}
                                        className="h-5 w-9 shrink-0 cursor-pointer appearance-none rounded-full bg-slate-200 transition before:block before:h-4 before:w-4 before:translate-x-0.5 before:rounded-full before:bg-white before:shadow before:transition checked:bg-brand-600 checked:before:translate-x-4"
                                    />
                                </label>
                            ))}
                        </div>
                        <button
                            onClick={save}
                            disabled={!changed || saving}
                            className="flex w-full items-center justify-center gap-2 rounded-xl border border-brand-200 px-4 py-2.5 text-sm font-bold text-brand-700 hover:bg-brand-50 disabled:opacity-50"
                        >
                            {saving ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} Simpan pilihan
                        </button>
                    </div>
                )}

                {devices.length > 0 && (
                    <div className="space-y-3 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-sm font-bold text-slate-700">Perangkat yang menerima</h2>
                        <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200">
                            {devices.map((d) => (
                                <li key={d.id} className="flex items-center gap-3 px-3 py-2.5">
                                    <Smartphone size={16} className="shrink-0 text-slate-400" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-bold text-slate-700">
                                            {d.name || 'Perangkat'}
                                            {d.endpoint_hash === mine && <span className="ml-1.5 rounded-full bg-brand-50 px-1.5 py-0.5 text-[10px] text-brand-700">HP ini</span>}
                                        </p>
                                        <p className="text-[11px] text-slate-400">
                                            Didaftarkan {fmtDate(d.created_at)}
                                            {d.last_used_at ? ` · terakhir terkirim ${fmtDate(d.last_used_at)}` : ''}
                                            {d.failures > 0 ? ` · ${d.failures}× gagal` : ''}
                                        </p>
                                    </div>
                                    <button onClick={() => remove(d.id)} title="Hapus" aria-label="Hapus" className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600">
                                        <Trash2 size={15} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </div>
    );
}
