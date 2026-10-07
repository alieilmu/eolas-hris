import { useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Check, KeyRound, Loader2, MapPin, Pencil, Plus, Power, Search, ShieldCheck, Trash2, UserPlus, Users as UsersIcon } from 'lucide-react';
import { AttendanceHeader, Modal, ghostButton, primaryButton, secondaryButton, smallInput } from '@/components/attendance/ui';
import type { MenuLevel } from '@/types';

interface AdminRow {
    id: number;
    name: string;
    email: string;
    role: 'Admin' | 'HR';
    permissions: Record<string, MenuLevel>;
    work_location_ids: number[];
    sub_location_ids: number[];
    is_active: boolean;
    last_login_at: string | null;
}

interface KaryawanRow {
    id: number;
    nrp: string;
    name: string;
    jabatan: string | null;
    work_location_id: number | null;
    work_location: string | null;
    sub_location: string | null;
    sub_location_id: number | null;
    has_tanggal_lahir: boolean;
    account: { id: number; is_active: boolean; must_change_password: boolean; last_login_at: string | null } | null;
}

interface MenuGroup {
    module: string;
    menus: { key: string; label: string }[];
}

interface Props {
    admins: AdminRow[];
    karyawans: KaryawanRow[];
    menus: MenuGroup[];
    locations: { id: number; name: string }[];
    sub_locations: { id: number; name: string; work_location_id: number }[];
    roles: Record<string, string>;
}

const visit = { preserveScroll: true, preserveState: true } as const;
const th = 'px-3 py-2.5 text-[10px] font-bold tracking-wide text-slate-500 uppercase';

/** Settings → Akun & Akses: admin accounts (menus and locations) and employee accounts for the portal. */
export default function Users(props: Props) {
    const [tab, setTab] = useState<'admin' | 'karyawan'>(() =>
        new URLSearchParams(window.location.search).get('tab') === 'karyawan' ? 'karyawan' : 'admin',
    );
    const go = (t: 'admin' | 'karyawan') => {
        setTab(t);
        window.history.replaceState(window.history.state, '', `/settings/akun${t === 'karyawan' ? '?tab=karyawan' : ''}`);
    };

    return (
        <>
            <Head title="Akun & Akses" />
            <AttendanceHeader title="Akun & Akses" subtitle="Akun admin dengan hak menu & lingkup lokasi, dan akun karyawan untuk Portal Karyawan">
                <div className="flex rounded-xl bg-slate-100 p-1">
                    {(
                        [
                            ['admin', 'Akun Admin', ShieldCheck],
                            ['karyawan', 'Akun Karyawan', UsersIcon],
                        ] as const
                    ).map(([value, label, Icon]) => (
                        <button
                            key={value}
                            onClick={() => go(value)}
                            className={`flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-sm font-bold ${tab === value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                        >
                            <Icon size={15} />
                            {label}
                        </button>
                    ))}
                </div>
            </AttendanceHeader>
            <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-6">
                {tab === 'admin' ? <AdminTab {...props} /> : <KaryawanTab {...props} />}
            </div>
        </>
    );
}

// --- admin accounts ---------------------------------------------------------------------------

function AdminTab({ admins, menus, locations, sub_locations, roles }: Props) {
    const [editing, setEditing] = useState<AdminRow | 'new' | null>(null);
    const me = usePage().props.auth.user?.id;
    const menuCount = menus.reduce((n, g) => n + g.menus.length, 0);
    const locationName = (ids: number[], subs: number[]) =>
        [...ids.map((id) => locations.find((l) => l.id === id)?.name), ...subs.map((id) => sub_locations.find((s) => s.id === id)?.name)].filter(
            Boolean,
        );

    return (
        <div className="rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <p className="text-xs text-slate-500">
                    <b>Super Admin</b> membuka semua modul dan lokasi, termasuk menu ini. <b>Admin</b> hanya membuka menu yang dicentang, untuk lokasi
                    yang dipilih.
                </p>
                <button onClick={() => setEditing('new')} className={primaryButton}>
                    <Plus size={16} />
                    Tambah akun admin
                </button>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className={th}>Nama</th>
                            <th className={th}>Jenis</th>
                            <th className={th}>Lingkup lokasi</th>
                            <th className={th}>Hak menu</th>
                            <th className={th}>Status</th>
                            <th className={th}>Login terakhir</th>
                            <th className={`${th} pr-5 text-right`}>Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {admins.map((a) => {
                            const levels = Object.values(a.permissions);
                            const places = locationName(a.work_location_ids, a.sub_location_ids);
                            return (
                                <tr key={a.id} className="hover:bg-slate-50/60">
                                    <td className="px-3 py-3 pl-5">
                                        <p className="font-bold text-slate-700">
                                            {a.name}
                                            {a.id === me && <span className="ml-1.5 text-[10px] font-bold text-brand-500">(Anda)</span>}
                                        </p>
                                        <p className="text-[11px] text-slate-400">{a.email}</p>
                                    </td>
                                    <td className="px-3 py-3">
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${a.role === 'Admin' ? 'bg-brand-600 text-white' : 'bg-brand-50 text-brand-700'}`}
                                        >
                                            {roles[a.role]}
                                        </span>
                                    </td>
                                    <td className="max-w-64 px-3 py-3 text-xs text-slate-600">
                                        {a.role === 'Admin' || !places.length ? (
                                            <span className="text-slate-400">Semua lokasi</span>
                                        ) : (
                                            <div className="flex flex-wrap gap-1">
                                                {places.map((p) => (
                                                    <span key={p} className="rounded-md bg-slate-100 px-1.5 py-0.5 font-bold text-slate-600">
                                                        {p}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-3 py-3 text-xs text-slate-600">
                                        {a.role === 'Admin'
                                            ? 'Semua menu (lihat & ubah)'
                                            : `${levels.length} dari ${menuCount} menu · ${levels.filter((l) => l === 'edit').length} bisa ubah`}
                                    </td>
                                    <td className="px-3 py-3">
                                        <span className={`text-xs font-bold ${a.is_active ? 'text-emerald-700' : 'text-slate-400'}`}>
                                            {a.is_active ? 'Aktif' : 'Nonaktif'}
                                        </span>
                                    </td>
                                    <td className="px-3 py-3 text-xs text-slate-500 tabular-nums">{a.last_login_at ?? '-'}</td>
                                    <td className="py-3 pr-5">
                                        <div className="flex justify-end gap-1">
                                            <button
                                                onClick={() => setEditing(a)}
                                                title="Ubah"
                                                className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                            >
                                                <Pencil size={15} />
                                            </button>
                                            <button
                                                disabled={a.id === me}
                                                onClick={() => confirm(`Hapus akun ${a.name}?`) && router.delete(`/settings/akun/${a.id}`, visit)}
                                                title={a.id === me ? 'Akun Anda sendiri' : 'Hapus'}
                                                className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600 disabled:opacity-30"
                                            >
                                                <Trash2 size={15} />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            {editing && (
                <AdminEditor
                    admin={editing === 'new' ? undefined : editing}
                    menus={menus}
                    locations={locations}
                    subLocations={sub_locations}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

function AdminEditor({
    admin,
    menus,
    locations,
    subLocations,
    onClose,
}: {
    admin?: AdminRow;
    menus: MenuGroup[];
    locations: Props['locations'];
    subLocations: Props['sub_locations'];
    onClose: () => void;
}) {
    const [name, setName] = useState(admin?.name ?? '');
    const [email, setEmail] = useState(admin?.email ?? '');
    const [password, setPassword] = useState('');
    const [role, setRole] = useState<'Admin' | 'HR'>(admin?.role ?? 'HR');
    const [active, setActive] = useState(admin?.is_active ?? true);
    const [permissions, setPermissions] = useState<Record<string, MenuLevel>>(admin?.role === 'HR' ? admin.permissions : {});
    const [allLocations, setAllLocations] = useState(!admin || (!admin.work_location_ids.length && !admin.sub_location_ids.length));
    const [locationIds, setLocationIds] = useState<Set<number>>(new Set(admin?.work_location_ids ?? []));
    const [subIds, setSubIds] = useState<Set<number>>(new Set(admin?.sub_location_ids ?? []));
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);
    const allKeys = menus.flatMap((g) => g.menus.map((m) => m.key));

    /** Lihat and Ubah as checkboxes: Ubah includes Lihat, removing Lihat removes Ubah. */
    const setLevel = (keys: string[], level: MenuLevel, on: boolean) =>
        setPermissions((prev) => {
            const next = { ...prev };
            for (const key of keys) {
                if (on) next[key] = level === 'edit' ? 'edit' : (next[key] ?? 'view');
                else if (level === 'view') delete next[key];
                else if (next[key] === 'edit') next[key] = 'view';
            }
            return next;
        });
    const has = (key: string, level: MenuLevel) => (level === 'view' ? permissions[key] !== undefined : permissions[key] === 'edit');
    const allHave = (keys: string[], level: MenuLevel) => keys.every((k) => has(k, level));
    const toggle = (set: Set<number>, id: number, on: boolean) => {
        const next = new Set(set);
        if (on) next.add(id);
        else next.delete(id);
        return next;
    };

    const save = () => {
        const data = {
            name,
            email,
            password: password || null,
            role,
            is_active: active,
            permissions,
            work_location_ids: allLocations ? [] : [...locationIds],
            // A sub location of a chosen work location is covered already.
            sub_location_ids: allLocations
                ? []
                : [...subIds].filter((id) => !locationIds.has(subLocations.find((s) => s.id === id)?.work_location_id ?? 0)),
        } as never;
        const options = {
            ...visit,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: onClose,
            onError: (e: Record<string, string>) => setErrors(Object.values(e)),
        };
        if (admin) router.put(`/settings/akun/${admin.id}`, data, options);
        else router.post('/settings/akun', data, options);
    };

    const input = `${smallInput} w-full`;
    const cell = 'px-3 py-1.5 text-center';

    return (
        <Modal
            title={admin ? `Ubah akun ${admin.name}` : 'Tambah akun admin'}
            subtitle="Password awal harus diganti pemiliknya saat login pertama."
            onClose={onClose}
            width="max-w-3xl"
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={save} disabled={busy} className={primaryButton}>
                        {busy ? <Loader2 size={16} className="animate-spin" /> : <Check size={16} />}
                        Simpan akun
                    </button>
                </>
            }
        >
            <div className="space-y-5">
                <div className="grid gap-3 sm:grid-cols-3">
                    <Labeled label="Nama">
                        <input value={name} onChange={(e) => setName(e.target.value)} className={input} />
                    </Labeled>
                    <Labeled label="Email (untuk login)">
                        <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} className={input} />
                    </Labeled>
                    <Labeled label={admin ? 'Password baru (kosongkan bila tetap)' : 'Password awal'}>
                        <input
                            type="password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            autoComplete="new-password"
                            className={input}
                        />
                    </Labeled>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <span className="text-[11px] font-bold text-slate-500">Jenis akun</span>
                    {(
                        [
                            ['HR', 'Admin', 'menu & lokasi sesuai pilihan'],
                            ['Admin', 'Super Admin', 'semua modul & lokasi + Settings'],
                        ] as const
                    ).map(([value, label, hint]) => (
                        <label
                            key={value}
                            className={`flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm ${role === value ? 'border-brand-500 bg-brand-50' : 'border-slate-200'}`}
                        >
                            <input type="radio" checked={role === value} onChange={() => setRole(value)} className="accent-brand-600" />
                            <b className="text-slate-700">{label}</b>
                            <span className="text-[11px] text-slate-400">{hint}</span>
                        </label>
                    ))}
                    <label className="ml-auto flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} className="accent-brand-600" />
                        Aktif
                    </label>
                </div>

                {role === 'HR' && (
                    <>
                        {/* Locations */}
                        <div className="space-y-2 rounded-2xl border border-slate-200 p-4">
                            <p className="flex items-center gap-1.5 text-sm font-bold text-slate-700">
                                <MapPin size={15} className="text-brand-500" />
                                Lingkup lokasi
                            </p>
                            <div className="flex gap-2">
                                {[
                                    [true, 'Semua lokasi'],
                                    [false, 'Pilih lokasi / sub lokasi'],
                                ].map(([value, label]) => (
                                    <label key={String(value)} className="flex items-center gap-1.5 text-sm text-slate-600">
                                        <input
                                            type="radio"
                                            checked={allLocations === value}
                                            onChange={() => setAllLocations(value as boolean)}
                                            className="accent-brand-600"
                                        />
                                        {label}
                                    </label>
                                ))}
                            </div>
                            {!allLocations && (
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {locations.map((l) => {
                                        const subs = subLocations.filter((s) => s.work_location_id === l.id);
                                        const whole = locationIds.has(l.id);
                                        return (
                                            <div key={l.id} className="rounded-xl bg-slate-50 p-2.5">
                                                <label className="flex items-center gap-2 text-sm font-bold text-slate-700">
                                                    <input
                                                        type="checkbox"
                                                        checked={whole}
                                                        onChange={(e) => setLocationIds((s) => toggle(s, l.id, e.target.checked))}
                                                        className="accent-brand-600"
                                                    />
                                                    {l.name}
                                                    {subs.length > 0 && (
                                                        <span className="text-[10px] font-normal text-slate-400">(semua sub lokasinya)</span>
                                                    )}
                                                </label>
                                                {subs.length > 0 && (
                                                    <div className="mt-1.5 ml-6 flex flex-wrap gap-x-3 gap-y-1">
                                                        {subs.map((s) => (
                                                            <label
                                                                key={s.id}
                                                                className={`flex items-center gap-1.5 text-xs ${whole ? 'text-slate-300' : 'text-slate-600'}`}
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    disabled={whole}
                                                                    checked={whole || subIds.has(s.id)}
                                                                    onChange={(e) => setSubIds((set) => toggle(set, s.id, e.target.checked))}
                                                                    className="accent-brand-600"
                                                                />
                                                                {s.name}
                                                            </label>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                            <p className="text-[11px] text-slate-400">
                                Berlaku untuk data karyawan di Man Power, Struktur Organisasi, Roster, Absensi, Trip & Leave, Deklarasi dan
                                Monitoring. Master data tetap global.
                            </p>
                        </div>

                        {/* Menus */}
                        <div className="overflow-hidden rounded-2xl border border-slate-200">
                            <table className="w-full text-sm">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className={`${th} text-left`}>Menu</th>
                                        {(['view', 'edit'] as const).map((level) => (
                                            <th key={level} className={`${th} w-28 text-center`}>
                                                <label className="flex cursor-pointer items-center justify-center gap-1.5">
                                                    <input
                                                        type="checkbox"
                                                        checked={allHave(allKeys, level)}
                                                        onChange={(e) => setLevel(allKeys, level, e.target.checked)}
                                                        className="accent-brand-600"
                                                    />
                                                    {level === 'view' ? 'Lihat' : 'Ubah'}
                                                </label>
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {menus.map((g) => {
                                        const keys = g.menus.map((m) => m.key);
                                        return [
                                            <tr key={g.module} className="bg-slate-50/60">
                                                <td className="px-3 py-1.5 text-[11px] font-black tracking-wide text-slate-500 uppercase">
                                                    Modul {g.module}
                                                </td>
                                                {(['view', 'edit'] as const).map((level) => (
                                                    <td key={level} className={cell}>
                                                        <input
                                                            type="checkbox"
                                                            checked={allHave(keys, level)}
                                                            onChange={(e) => setLevel(keys, level, e.target.checked)}
                                                            aria-label={`${level} semua ${g.module}`}
                                                            className="accent-brand-600"
                                                        />
                                                    </td>
                                                ))}
                                            </tr>,
                                            ...g.menus.map((m) => (
                                                <tr key={m.key} className="border-t border-slate-100">
                                                    <td className="px-3 py-1.5 pl-6 text-slate-700">{m.label}</td>
                                                    {(['view', 'edit'] as const).map((level) => (
                                                        <td key={level} className={cell}>
                                                            <input
                                                                type="checkbox"
                                                                checked={has(m.key, level)}
                                                                onChange={(e) => setLevel([m.key], level, e.target.checked)}
                                                                aria-label={`${level} ${m.label}`}
                                                                className="accent-brand-600"
                                                            />
                                                        </td>
                                                    ))}
                                                </tr>
                                            )),
                                        ];
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                {errors.length > 0 && (
                    <div className="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        {errors.map((e, i) => (
                            <p key={i}>{e}</p>
                        ))}
                    </div>
                )}
            </div>
        </Modal>
    );
}

// --- employee accounts --------------------------------------------------------------------------

function KaryawanTab({ karyawans, locations }: Props) {
    const [q, setQ] = useState('');
    const [lokasi, setLokasi] = useState('');
    const [akun, setAkun] = useState<'' | 'belum' | 'aktif' | 'nonaktif'>('');
    const [selected, setSelected] = useState<Set<number>>(new Set());

    const rows = useMemo(() => {
        const s = q.trim().toLowerCase();
        return karyawans.filter(
            (k) =>
                (!s || k.name.toLowerCase().includes(s) || k.nrp.includes(s)) &&
                (!lokasi || String(k.work_location_id) === lokasi) &&
                (!akun || (akun === 'belum' ? !k.account : akun === 'aktif' ? k.account?.is_active : k.account && !k.account.is_active)),
        );
    }, [karyawans, q, lokasi, akun]);
    const withoutAccount = rows.filter((k) => !k.account);
    const ticked = rows.filter((k) => selected.has(k.id));
    const chosen = ticked.filter((k) => !k.account);
    const chosenAccounts = ticked.filter((k) => k.account);
    const allTicked = rows.length > 0 && rows.every((k) => selected.has(k.id));

    const create = (ids: number[]) => {
        if (!ids.length || !confirm(`Buat ${ids.length} akun karyawan? Login dengan NRP, password awal tanggal lahir (ddmmyyyy).`)) return;
        router.post('/settings/akun-karyawan', { karyawan_ids: ids }, { ...visit, onSuccess: () => setSelected(new Set()) });
    };
    const resetMany = () => {
        const ids = chosenAccounts.map((k) => k.account!.id);
        if (
            !ids.length ||
            !confirm(
                `Reset password ${ids.length} akun karyawan ke password awal (tanggal lahir ddmmyyyy, atau NRP bila tanggal lahir kosong)?\n\nPassword yang sudah mereka ganti tidak berlaku lagi, dan mereka wajib menggantinya saat login berikutnya.`,
            )
        )
            return;
        router.post('/settings/akun-karyawan/reset-masal', { user_ids: ids }, { ...visit, onSuccess: () => setSelected(new Set()) });
    };

    return (
        <div className="rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                <div className="relative">
                    <Search size={15} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama / NRP" className={`${smallInput} w-52 pl-8`} />
                </div>
                <select value={lokasi} onChange={(e) => setLokasi(e.target.value)} className={smallInput} aria-label="Lokasi kerja">
                    <option value="">Semua lokasi</option>
                    {locations.map((l) => (
                        <option key={l.id} value={l.id}>
                            {l.name}
                        </option>
                    ))}
                </select>
                <select value={akun} onChange={(e) => setAkun(e.target.value as typeof akun)} className={smallInput} aria-label="Status akun">
                    <option value="">Semua status akun</option>
                    <option value="belum">Belum punya akun</option>
                    <option value="aktif">Akun aktif</option>
                    <option value="nonaktif">Akun nonaktif</option>
                </select>
                <div className="ml-auto flex flex-wrap gap-2">
                    <button onClick={resetMany} disabled={!chosenAccounts.length} className={secondaryButton}>
                        <KeyRound size={15} />
                        Reset password yang dicentang ({chosenAccounts.length})
                    </button>
                    <button onClick={() => create(chosen.map((k) => k.id))} disabled={!chosen.length} className={secondaryButton}>
                        <UserPlus size={15} />
                        Buat akun yang dicentang ({chosen.length})
                    </button>
                    <button onClick={() => create(withoutAccount.map((k) => k.id))} disabled={!withoutAccount.length} className={primaryButton}>
                        <UserPlus size={15} />
                        Buat untuk semua yang tampil ({withoutAccount.length})
                    </button>
                </div>
            </div>
            <p className="border-b border-slate-100 bg-slate-50/60 px-5 py-2 text-[11px] text-slate-500">
                Karyawan login di halaman yang sama dengan <b>NRP</b>. Password awal = <b>tanggal lahir (ddmmyyyy)</b>, atau NRP bila tanggal lahir
                belum diisi; wajib diganti saat login pertama. Karyawan yang sudah keluar tidak bisa login. Centang akun yang sudah ada lalu{' '}
                <b>Reset password yang dicentang</b> untuk mengembalikan banyak password sekaligus (mis. pakai filter lokasi, lalu centang semua).
            </p>
            <div className="max-h-[calc(100vh-17rem)] overflow-auto">
                <table className="w-full text-left text-sm">
                    <thead className="sticky top-0 z-10 bg-slate-50">
                        <tr>
                            <th className={`${th} w-10 pl-5`}>
                                <input
                                    type="checkbox"
                                    checked={allTicked}
                                    onChange={(e) => setSelected(e.target.checked ? new Set(rows.map((k) => k.id)) : new Set())}
                                    aria-label="Centang semua yang tampil"
                                    title="Centang semua yang tampil"
                                    className="accent-brand-600"
                                />
                            </th>
                            <th className={th}>NRP</th>
                            <th className={th}>Nama</th>
                            <th className={th}>Lokasi</th>
                            <th className={th}>Akun</th>
                            <th className={th}>Login terakhir</th>
                            <th className={`${th} pr-5 text-right`}>Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((k) => (
                            <tr key={k.id} className="hover:bg-slate-50/60">
                                <td className="py-2 pl-5">
                                    <input
                                        type="checkbox"
                                        checked={selected.has(k.id)}
                                        onChange={(e) => setSelected((s) => toggleSet(s, k.id, e.target.checked))}
                                        aria-label={`Centang ${k.name}`}
                                        className="accent-brand-600"
                                    />
                                </td>
                                <td className="px-3 py-2 font-mono text-xs text-slate-500">{k.nrp}</td>
                                <td className="px-3 py-2">
                                    <p className="font-bold text-slate-700">{k.name}</p>
                                    <p className="max-w-72 truncate text-[11px] text-slate-400">{k.jabatan ?? 'Belum ditempatkan'}</p>
                                </td>
                                <td className="px-3 py-2 text-xs text-slate-600">
                                    {k.work_location ?? '-'}
                                    {k.sub_location && <span className="text-slate-400"> · {k.sub_location}</span>}
                                </td>
                                <td className="px-3 py-2">
                                    {!k.account ? (
                                        <span className="text-xs text-slate-400">Belum ada{!k.has_tanggal_lahir && ' (tgl lahir kosong)'}</span>
                                    ) : (
                                        <span className="flex flex-wrap gap-1">
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${k.account.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}
                                            >
                                                {k.account.is_active ? 'Aktif' : 'Nonaktif'}
                                            </span>
                                            {k.account.must_change_password && (
                                                <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-700">
                                                    Belum ganti password
                                                </span>
                                            )}
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-2 text-xs text-slate-500 tabular-nums">{k.account?.last_login_at ?? '-'}</td>
                                <td className="py-2 pr-5">
                                    {k.account && (
                                        <div className="flex justify-end gap-1">
                                            <button
                                                onClick={() =>
                                                    confirm(`Kembalikan password ${k.name} ke password awal?`) &&
                                                    router.post(`/settings/akun-karyawan/${k.account!.id}/reset`, {}, visit)
                                                }
                                                title="Reset password"
                                                className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                            >
                                                <KeyRound size={15} />
                                            </button>
                                            <button
                                                onClick={() => router.post(`/settings/akun-karyawan/${k.account!.id}/status`, {}, visit)}
                                                title={k.account.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                                                className={`rounded-lg p-1.5 hover:bg-slate-100 ${k.account.is_active ? 'text-slate-400' : 'text-emerald-600'}`}
                                            >
                                                <Power size={15} />
                                            </button>
                                            <button
                                                onClick={() =>
                                                    confirm(`Hapus akun karyawan ${k.name}? Riwayat pengajuannya tetap tersimpan.`) &&
                                                    router.delete(`/settings/akun-karyawan/${k.account!.id}`, visit)
                                                }
                                                title="Hapus akun"
                                                className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                            >
                                                <Trash2 size={15} />
                                            </button>
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function toggleSet(set: Set<number>, id: number, on: boolean) {
    const next = new Set(set);
    if (on) next.add(id);
    else next.delete(id);
    return next;
}

function Labeled({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <label className="mb-1 block text-[11px] font-bold text-slate-500">{label}</label>
            {children}
        </div>
    );
}
