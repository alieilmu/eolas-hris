import { Fragment, useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { CalendarPlus, Check, ChevronDown, Database, FlaskConical, Loader2, Pencil, Power, RefreshCw, Search, Trash2, X } from 'lucide-react';

interface Account {
    id: number;
    code: string;
    name: string;
    email: string;
    company_name: string;
    position: string | null;
    phone: string | null;
    company_size: string | null;
    requirements: string | null;
    data_source: 'demo' | 'blank';
    industry_label: string | null;
    source_label: string;
    status: 'pending' | 'provisioning' | 'active' | 'inactive' | 'failed' | 'ends';
    status_label: string;
    notes: string | null;
    expires_at: string | null;
    days_left: number | null;
    last_login_at: string | null;
    created_at: string | null;
}

interface Props {
    accounts: Account[];
    auto_approve: boolean;
    demo_days: number;
    stats: { total: number; active: number; pending: number; demo: number; blank: number };
}

const STATUS_TONE: Record<Account['status'], string> = {
    pending: 'bg-amber-100 text-amber-700',
    provisioning: 'bg-sky-100 text-sky-700',
    active: 'bg-brand-100 text-brand-700',
    inactive: 'bg-slate-100 text-slate-500',
    failed: 'bg-rose-100 text-rose-600',
    ends: 'bg-slate-100 text-slate-500',
};

const dt = (s: string | null) => (s ? new Date(s.replace(' ', 'T')).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '–');

/** Settings → Akun Demo: the requests, their workspaces, and what the Super Admin can do with them. */
export default function TrialAccounts({ accounts, auto_approve, demo_days, stats }: Props) {
    const [q, setQ] = useState('');
    const [open, setOpen] = useState<number | null>(null);
    const busy = accounts.some((a) => a.status === 'provisioning');

    useEffect(() => {
        if (!busy) return;
        const t = setInterval(() => router.reload({ only: ['accounts', 'stats'] }), 3000);
        return () => clearInterval(t);
    }, [busy]);

    const post = (url: string, data: Record<string, string | number> = {}, ask?: string) => {
        if (ask && !confirm(ask)) return;
        router.post(url, data, { preserveScroll: true });
    };
    const shown = accounts.filter((a) => `${a.name} ${a.email} ${a.company_name} ${a.code}`.toLowerCase().includes(q.toLowerCase()));

    return (
        <>
            <Head title="Akun Demo" />
            <header className="flex h-16 shrink-0 items-center justify-between border-b-[3px] border-accent-400 bg-white px-8">
                <h1 className="text-xl font-bold tracking-tight text-slate-800">Akun Demo</h1>
                {busy && (
                    <span className="flex items-center gap-2 text-xs font-bold text-sky-600">
                        <Loader2 size={14} className="animate-spin" /> Menyiapkan workspace…
                    </span>
                )}
            </header>

            <div className="flex-1 space-y-6 overflow-y-auto bg-slate-50 p-6 lg:p-8">
                <div className="grid grid-cols-2 gap-4 md:grid-cols-5">
                    {[
                        ['Total pengajuan', stats.total],
                        ['Workspace aktif', stats.active],
                        ['Menunggu persetujuan', stats.pending],
                        ['Pakai data demo', stats.demo],
                        ['Pakai data sendiri', stats.blank],
                    ].map(([l, v]) => (
                        <div key={l} className="rounded-2xl border border-slate-100 bg-white p-4">
                            <p className="text-xs font-semibold text-slate-400">{l}</p>
                            <p className="mt-1 text-2xl font-black text-slate-800">{v}</p>
                        </div>
                    ))}
                </div>

                <div className="rounded-2xl border border-brand-100 bg-brand-50/60 p-4 text-sm text-slate-600">
                    Pengajuan dari halaman publik <b>/demo</b>{' '}
                    {auto_approve ? 'langsung disiapkan otomatis' : 'menunggu persetujuan Anda di sini'}. Masa demo {demo_days} hari. Setiap akun
                    mendapat database dan folder file sendiri, terpisah dari data utama. Atur lewat <code className="text-xs">DEMO_AUTO_APPROVE</code> dan{' '}
                    <code className="text-xs">DEMO_DAYS</code> di .env.
                </div>

                <div className="rounded-2xl border border-slate-100 bg-white">
                    <div className="flex items-center gap-2 border-b border-slate-100 p-3">
                        <Search size={16} className="ml-1 text-slate-400" />
                        <input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Cari nama, email, perusahaan, kode…"
                            className="flex-1 bg-transparent text-sm outline-none"
                        />
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 text-left text-[11px] font-bold tracking-wide text-slate-400 uppercase">
                                <tr>
                                    <th className="px-4 py-2.5">Pemohon</th>
                                    <th className="px-4 py-2.5">Perusahaan</th>
                                    <th className="px-4 py-2.5">Data</th>
                                    <th className="px-4 py-2.5">Status</th>
                                    <th className="px-4 py-2.5">Berakhir</th>
                                    <th className="px-4 py-2.5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((a) => (
                                    <Fragment key={a.id}>
                                        <tr className="border-t border-slate-100 align-top">
                                            <td className="px-4 py-3">
                                                <button onClick={() => setOpen(open === a.id ? null : a.id)} className="flex items-start gap-1.5 text-left">
                                                    <ChevronDown size={15} className={`mt-0.5 text-slate-400 transition ${open === a.id ? 'rotate-180' : ''}`} />
                                                    <span>
                                                        <span className="block font-bold text-slate-800">{a.name}</span>
                                                        <span className="block text-xs text-slate-400">{a.email}</span>
                                                    </span>
                                                </button>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="block font-semibold text-slate-700">{a.company_name}</span>
                                                <span className="block font-mono text-xs text-brand-600">{a.code}</span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                                                    {a.data_source === 'demo' ? <FlaskConical size={14} className="text-brand-600" /> : <Database size={14} className="text-slate-500" />}
                                                    {a.source_label}{a.industry_label ? ` · ${a.industry_label}` : ''}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold ${STATUS_TONE[a.status]}`}>
                                                    {a.status === 'provisioning' && <Loader2 size={12} className="animate-spin" />}
                                                    {a.status_label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-xs text-slate-500">
                                                <ExpiryCell account={a} />
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap justify-end gap-1.5">
                                                    {(a.status === 'pending' || a.status === 'failed') && (
                                                        <button
                                                            onClick={() => post(`/settings/trial-accounts/${a.id}/approve`)}
                                                            className="flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1.5 text-xs font-bold text-white hover:bg-brand-700"
                                                        >
                                                            <Check size={13} /> {a.status === 'failed' ? 'Coba lagi' : 'Setujui'}
                                                        </button>
                                                    )}
                                                    {['active', 'inactive', 'ends'].includes(a.status) && (
                                                        <>
                                                            <button
                                                                title={`Perpanjang ${demo_days} hari`}
                                                                onClick={() => post(`/settings/trial-accounts/${a.id}/extend`, { days: demo_days })}
                                                                className="flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50"
                                                            >
                                                                <CalendarPlus size={13} /> +{demo_days} hari
                                                            </button>
                                                            <button
                                                                title={a.status === 'inactive' ? 'Aktifkan' : 'Nonaktifkan'}
                                                                onClick={() => post(`/settings/trial-accounts/${a.id}/status`)}
                                                                className="rounded-lg border border-slate-200 p-1.5 text-slate-500 hover:bg-slate-50"
                                                            >
                                                                <Power size={14} />
                                                            </button>
                                                            <button
                                                                title="Siapkan ulang workspace"
                                                                onClick={() =>
                                                                    post(
                                                                        `/settings/trial-accounts/${a.id}/reset`,
                                                                        { data_source: a.data_source },
                                                                        `Siapkan ulang workspace ${a.company_name} dengan ${a.source_label}? Semua data di dalamnya diganti.`,
                                                                    )
                                                                }
                                                                className="rounded-lg border border-slate-200 p-1.5 text-slate-500 hover:bg-slate-50"
                                                            >
                                                                <RefreshCw size={14} />
                                                            </button>
                                                        </>
                                                    )}
                                                    {a.status !== 'provisioning' && (
                                                        <button
                                                            title="Hapus akun & workspace"
                                                            onClick={() =>
                                                                confirm(`Hapus akun demo ${a.company_name} beserta seluruh workspace-nya? Tidak bisa dibatalkan.`) &&
                                                                router.delete(`/settings/trial-accounts/${a.id}`, { preserveScroll: true })
                                                            }
                                                            className="rounded-lg border border-rose-100 p-1.5 text-rose-500 hover:bg-rose-50"
                                                        >
                                                            <Trash2 size={14} />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                        {open === a.id && (
                                            <tr className="bg-slate-50/60">
                                                <td colSpan={6} className="px-10 py-3 text-xs text-slate-600">
                                                    <div className="grid gap-x-8 gap-y-1.5 md:grid-cols-3">
                                                        <p>
                                                            <b>Jabatan:</b> {a.position ?? '–'}
                                                        </p>
                                                        <p>
                                                            <b>No. HP:</b> {a.phone ?? '–'}
                                                        </p>
                                                        <p>
                                                            <b>Jumlah karyawan:</b> {a.company_size ?? '–'}
                                                        </p>
                                                        <p>
                                                            <b>Diajukan:</b> {dt(a.created_at)}
                                                        </p>
                                                        <p>
                                                            <b>Login terakhir:</b> {dt(a.last_login_at)}
                                                        </p>
                                                        <p className="md:col-span-3">
                                                            <b>Kebutuhan:</b> {a.requirements ?? '–'}
                                                        </p>
                                                        {a.notes && <p className="text-rose-600 md:col-span-3">{a.notes}</p>}
                                                        {['active', 'inactive', 'ends'].includes(a.status) && (
                                                            <p className="md:col-span-3">
                                                                <button
                                                                    onClick={() =>
                                                                        post(
                                                                            `/settings/trial-accounts/${a.id}/reset`,
                                                                            { data_source: a.data_source === 'demo' ? 'blank' : 'demo' },
                                                                            'Ganti sumber data workspace ini? Semua data di dalamnya diganti.',
                                                                        )
                                                                    }
                                                                    className="font-bold text-brand-700 hover:underline"
                                                                >
                                                                    Ganti ke {a.data_source === 'demo' ? 'data sendiri (kosong)' : 'data demo sistem'}
                                                                </button>
                                                            </p>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                                {shown.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-10 text-center text-sm text-slate-400">
                                            Belum ada pengajuan akun demo.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}

/** The end of the demo: shown with the days left, changed by hand with a date picker. */
function ExpiryCell({ account }: { account: Account }) {
    const current = account.expires_at?.slice(0, 10) ?? '';
    const [editing, setEditing] = useState(false);
    const [date, setTanggal] = useState(current);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const save = () =>
        router.put(
            `/settings/trial-accounts/${account.id}/ends`,
            { date },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    setEditing(false);
                    setError(null);
                },
                onError: (e) => setError(e.date ?? 'Tanggal tidak valid.'),
            },
        );

    if (editing) {
        return (
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-1">
                    <input
                        type="date"
                        value={date}
                        onChange={(e) => setTanggal(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && date) save();
                            if (e.key === 'Escape') setEditing(false);
                        }}
                        autoFocus
                        className="rounded-lg border border-slate-200 px-2 py-1 text-xs text-slate-700 outline-none focus:ring-2 focus:ring-brand-200"
                    />
                    <button
                        onClick={save}
                        disabled={!date || busy}
                        title="Simpan"
                        className="rounded-lg bg-brand-600 p-1.5 text-white hover:bg-brand-700 disabled:opacity-40"
                    >
                        {busy ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />}
                    </button>
                    <button
                        onClick={() => {
                            setEditing(false);
                            setTanggal(current);
                            setError(null);
                        }}
                        title="Batal"
                        className="rounded-lg border border-slate-200 p-1.5 text-slate-500 hover:bg-slate-50"
                    >
                        <X size={13} />
                    </button>
                </div>
                <span className="text-[11px] text-slate-400">Berakhir di akhir hari (23.59)</span>
                {error && <span className="text-[11px] font-semibold text-rose-600">{error}</span>}
            </div>
        );
    }

    return (
        <button
            onClick={() => {
                setTanggal(current || new Date().toISOString().slice(0, 10));
                setEditing(true);
            }}
            title="Ubah tanggal berakhir"
            className="group flex items-start gap-1.5 rounded-lg text-left hover:text-brand-700"
        >
            <span>
                {account.expires_at ? dt(account.expires_at) : 'Belum diatur'}
                {account.days_left !== null && (
                    <span className={`block ${account.days_left === 0 ? 'text-rose-500' : 'text-slate-400'}`}>
                        {account.days_left === 0 ? 'Sudah berakhir' : `${account.days_left} hari lagi`}
                    </span>
                )}
            </span>
            <Pencil size={12} className="mt-0.5 shrink-0 text-slate-300 group-hover:text-brand-600" />
        </button>
    );
}
