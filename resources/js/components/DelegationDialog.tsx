import { useEffect, useState } from 'react';
import { Loader2, Trash2, UserRoundCheck } from 'lucide-react';
import { DateInput } from '@/components/DateInput';
import { Modal, ghostButton, inputClass, primaryButton } from '@/components/time/ui';
import { deleteJson, postJson } from '@/lib/http';

interface Data {
    own: { id: number; delegate: string | null; mode: 'range' | 'on_leave'; period: string; notes: string | null; applies_today: boolean }[];
    acting_for: string[];
    users: { id: number; label: string }[];
    modes: { value: 'range' | 'on_leave'; label: string }[];
}

/**
 * Delegasi: the substitute who approves in this account's name — for a range of dates, or on
 * every day of the account's approved leave — and the delegations running now.
 */
export function DelegationDialog({ onClose }: { onClose: () => void }) {
    const [data, setData] = useState<Data | null>(null);
    const [form, setForm] = useState({ delegate_id: '', mode: 'range' as 'range' | 'on_leave', starts_on: '', ends_on: '', notes: '' });
    const [query, setQuery] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const load = () =>
        fetch('/approvals/delegations', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then(setData);
    useEffect(() => {
        load();
    }, []);

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            await postJson('/approvals/delegations', { ...form, delegate_id: Number(form.delegate_id) || null });
            setForm({ delegate_id: '', mode: form.mode, starts_on: '', ends_on: '', notes: '' });
            setQuery('');
            await load();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    const stop = async (id: number) => {
        if (!confirm('Hentikan delegasi ini?')) return;
        await deleteJson(`/approvals/delegations/${id}`).catch((e: Error) => alert(e.message));
        load();
    };

    const matches = data?.users.filter((u) => u.label.toLowerCase().includes(query.toLowerCase())).slice(0, 50) ?? [];

    return (
        <Modal title="Delegasi persetujuan" subtitle="Pengganti menyetujui atas nama Anda selama delegasi berlaku" onClose={onClose}>
            {!data ? (
                <p className="flex items-center gap-2 py-6 text-sm text-slate-400">
                    <Loader2 size={15} className="animate-spin" /> Memuat…
                </p>
            ) : (
                <div className="space-y-5">
                    {data.acting_for.length > 0 && (
                        <p className="rounded-xl bg-sky-50 px-3 py-2 text-sm text-sky-800">
                            Hari ini Anda menggantikan <b>{data.acting_for.join(', ')}</b>.
                        </p>
                    )}
                    <div className="space-y-2">
                        <p className="text-xs font-bold tracking-wide text-slate-500 uppercase">Delegasi Anda</p>
                        {data.own.length === 0 && <p className="text-sm text-slate-400">Belum ada delegasi.</p>}
                        {data.own.map((d) => (
                            <div key={d.id} className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                <UserRoundCheck size={16} className={d.applies_today ? 'text-brand-600' : 'text-slate-300'} />
                                <div className="min-w-0 flex-1">
                                    <p className="font-bold text-slate-700">{d.delegate}</p>
                                    <p className="text-xs text-slate-500">
                                        {d.period}
                                        {d.applies_today && <span className="font-bold text-brand-700"> · berlaku hari ini</span>}
                                        {d.notes && ` · ${d.notes}`}
                                    </p>
                                </div>
                                <button onClick={() => stop(d.id)} aria-label="Hentikan" className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600">
                                    <Trash2 size={15} />
                                </button>
                            </div>
                        ))}
                    </div>

                    <div className="space-y-3 rounded-2xl bg-slate-50 p-4">
                        <p className="text-xs font-bold tracking-wide text-slate-500 uppercase">Tambah pengganti</p>
                        <div>
                            <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Cari nama atau NRP…" className={inputClass} />
                            {query && !form.delegate_id && (
                                <div className="mt-1 max-h-44 overflow-y-auto rounded-xl border border-slate-200 bg-white">
                                    {matches.map((u) => (
                                        <button
                                            key={u.id}
                                            onClick={() => (setForm({ ...form, delegate_id: String(u.id) }), setQuery(u.label))}
                                            className="block w-full px-3 py-1.5 text-left text-sm hover:bg-brand-50"
                                        >
                                            {u.label}
                                        </button>
                                    ))}
                                    {matches.length === 0 && <p className="px-3 py-2 text-sm text-slate-400">Tidak ditemukan.</p>}
                                </div>
                            )}
                            {form.delegate_id && (
                                <button onClick={() => (setForm({ ...form, delegate_id: '' }), setQuery(''))} className="mt-1 text-xs font-bold text-slate-500 hover:underline">
                                    Ganti pilihan
                                </button>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {data.modes.map((m) => (
                                <label key={m.value} className="flex items-center gap-1.5 text-sm text-slate-600">
                                    <input type="radio" checked={form.mode === m.value} onChange={() => setForm({ ...form, mode: m.value })} /> {m.label}
                                </label>
                            ))}
                        </div>
                        {form.mode === 'range' && (
                            <div className="grid grid-cols-2 gap-2">
                                <DateInput value={form.starts_on} onChange={(v) => setForm({ ...form, starts_on: v })} className={inputClass} />
                                <DateInput value={form.ends_on} onChange={(v) => setForm({ ...form, ends_on: v })} className={inputClass} />
                            </div>
                        )}
                        <input value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} maxLength={255} placeholder="Keterangan (opsional), mis. dinas ke site" className={inputClass} />
                        {error && <p className="text-sm font-semibold text-rose-600">{error}</p>}
                        <div className="flex justify-end gap-2">
                            <button onClick={onClose} className={ghostButton}>
                                Tutup
                            </button>
                            <button onClick={save} disabled={busy || !form.delegate_id} className={primaryButton}>
                                {busy && <Loader2 size={15} className="animate-spin" />} Simpan
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </Modal>
    );
}
