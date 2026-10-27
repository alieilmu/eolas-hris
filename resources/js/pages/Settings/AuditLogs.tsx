import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ScrollText, Search } from 'lucide-react';
import { AttendanceHeader, smallInput } from '@/components/time/ui';
import { DateInput } from '@/components/DateInput';

interface Log {
    id: number;
    at: string | null;
    user: string | null;
    user_id: number | null;
    action: string;
    label: string;
    subject: string | null;
    description: string | null;
    meta: Record<string, unknown> | null;
    ip: string | null;
    user_agent: string | null;
}

interface Props {
    logs: { data: Log[]; links: { url: string | null; label: string; active: boolean }[]; total: number; from: number | null; to: number | null };
    filters: { action?: string; group?: string; user?: number; from?: string; to?: string; q?: string };
    actions: { group: string; items: { value: string; label: string }[] }[];
    users: { id: number; name: string }[];
}

const TONE: Record<string, string> = {
    auth: 'bg-sky-50 text-sky-700',
    payroll: 'bg-brand-50 text-brand-700',
    salary: 'bg-amber-50 text-amber-700',
    employees: 'bg-violet-50 text-violet-700',
    users: 'bg-rose-50 text-rose-700',
};

/** Administrasi → Audit Log: sensitive actions, newest first, with filters. */
export default function AuditLogs({ logs, filters, actions, users }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [open, setOpen] = useState<number | null>(null);
    const go = (change: Partial<Props['filters']>) =>
        router.get('/settings/audit-log', { ...filters, ...change } as never, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Audit Log" />
            <AttendanceHeader title="Audit Log" subtitle="Siapa melakukan apa pada data gaji, proses gaji, ekspor data karyawan, password dan 2FA" />
            <main className="flex-1 space-y-3 overflow-y-auto bg-slate-50 p-6">
                <div className="flex flex-wrap items-center gap-2">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({ q: q || undefined });
                        }}
                        className="relative"
                    >
                        <Search size={14} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari keterangan / nama / IP" className={`${smallInput} w-64 pl-8`} />
                    </form>
                    <select
                        value={filters.action ?? (filters.group ? `group:${filters.group}` : '')}
                        onChange={(e) => {
                            const v = e.target.value;
                            go(v.startsWith('group:') ? { group: v.slice(6), action: undefined } : { action: v || undefined, group: undefined });
                        }}
                        className={smallInput}
                        aria-label="Aksi"
                    >
                        <option value="">Semua aksi</option>
                        {actions.map((g) => (
                            <optgroup key={g.group} label={g.group}>
                                <option value={`group:${g.group}`}>Semua {g.group.toLowerCase()}</option>
                                {g.items.map((a) => (
                                    <option key={a.value} value={a.value}>
                                        {a.label}
                                    </option>
                                ))}
                            </optgroup>
                        ))}
                    </select>
                    <select value={filters.user ?? ''} onChange={(e) => go({ user: e.target.value ? Number(e.target.value) : undefined })} className={smallInput} aria-label="Akun">
                        <option value="">Semua akun</option>
                        {users.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </select>
                    <div className="w-36">
                        <DateInput value={filters.from ?? ''} onChange={(v) => go({ from: v || undefined })} className={smallInput} />
                    </div>
                    <span className="text-slate-400">–</span>
                    <div className="w-36">
                        <DateInput value={filters.to ?? ''} onChange={(v) => go({ to: v || undefined })} className={smallInput} />
                    </div>
                    <span className="ml-auto text-xs text-slate-400">{logs.total} catatan</span>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    {logs.data.length === 0 ? (
                        <p className="flex flex-col items-center gap-2 py-16 text-sm text-slate-400">
                            <ScrollText size={26} /> Belum ada catatan yang cocok.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-bold text-slate-500">
                                <tr>
                                    <th className="px-4 py-2.5">Waktu</th>
                                    <th className="px-4 py-2.5">Akun</th>
                                    <th className="px-4 py-2.5">Aksi</th>
                                    <th className="px-4 py-2.5">Keterangan</th>
                                    <th className="px-4 py-2.5">IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.data.map((l) => (
                                    <tr key={l.id} onClick={() => setOpen(open === l.id ? null : l.id)} className="cursor-pointer border-t border-slate-100 align-top hover:bg-slate-50/60">
                                        <td className="px-4 py-2 text-xs whitespace-nowrap text-slate-500 tabular-nums">{l.at}</td>
                                        <td className="px-4 py-2 text-slate-700">{l.user ?? <span className="text-slate-400">–</span>}</td>
                                        <td className="px-4 py-2">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-bold whitespace-nowrap ${TONE[l.action.split('.')[0]] ?? 'bg-slate-100 text-slate-600'}`}>
                                                {l.label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2 text-slate-600">
                                            {l.description ?? l.subject ?? ''}
                                            {open === l.id && (
                                                <div className="mt-1.5 space-y-0.5 text-[11px] text-slate-400">
                                                    {l.subject && <p>Objek: {l.subject}</p>}
                                                    {l.meta && <pre className="font-mono whitespace-pre-wrap">{JSON.stringify(l.meta, null, 1)}</pre>}
                                                    {l.user_agent && <p>{l.user_agent}</p>}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-xs text-slate-500 tabular-nums">{l.ip}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {logs.links.length > 3 && (
                    <nav className="flex flex-wrap justify-center gap-1">
                        {logs.links.map((link, i) =>
                            link.url ? (
                                <Link
                                    key={i}
                                    href={link.url}
                                    preserveScroll
                                    className={`rounded-lg px-3 py-1.5 text-sm ${link.active ? 'bg-brand-600 font-bold text-white' : 'bg-white text-slate-600 hover:bg-slate-100'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span key={i} className="px-3 py-1.5 text-sm text-slate-300" dangerouslySetInnerHTML={{ __html: link.label }} />
                            ),
                        )}
                    </nav>
                )}
            </main>
        </>
    );
}
