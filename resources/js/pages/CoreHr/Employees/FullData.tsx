import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Download, FileSpreadsheet, IdCard, Lock, LogOut, Network, Plus, Search, Upload, UserPlus } from 'lucide-react';
import { AddEmployeeDialog } from '@/components/core_hr/AddEmployeeDialog';
import { TerminateEmployeeDialog } from '@/components/core_hr/TerminateEmployeeDialog';
import { EmployeeHeader } from '@/components/core_hr/EmployeeHeader';
import { EmployeeImportDialog } from '@/components/core_hr/EmployeeImportDialog';
import { displayValue } from '@/lib/format';
import type { ColumnGroup, EmployeeRow } from '@/types/core_hr';

// Left offset of "Nama", the 2nd frozen column right after NRP. Safe to fix
// against group 1 alone since that group is locked and therefore always shown.
const NRP_WIDTH = 110;

const chipStyle = (active: boolean) =>
    `flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[11px] font-bold ${
        active
            ? 'border-brand-600 bg-brand-600 text-white'
            : 'border-dashed border-slate-300 bg-white text-slate-400 hover:bg-slate-50 hover:text-slate-600'
    }`;

const toolbarButton =
    'flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 transition-all hover:bg-slate-50';

const chipMotion = {
    layout: true,
    transition: { duration: 0.22, ease: 'easeOut' as const },
};

interface Props {
    rows: EmployeeRow[];
    groups: ColumnGroup[];
    reason_options: string[];
}

export default function FullData({ rows, groups, reason_options: reasonOptions }: Props) {
    const [search, setSearch] = useState('');
    const [hiddenGroups, setHiddenGroups] = useState<Record<string, boolean>>({});
    const [showImport, setShowImport] = useState(false);
    const [showAdd, setShowAdd] = useState(false);
    const [exiting, setExiting] = useState<EmployeeRow | null>(null);

    const isHidden = (g: ColumnGroup) => !g.locked && !!hiddenGroups[g.id];
    const toggleGroup = (g: ColumnGroup) => {
        if (g.locked) return; // Group 1 carries NRP / Nama and always stays.
        setHiddenGroups((prev) => ({ ...prev, [g.id]: !prev[g.id] }));
    };

    const activeGroups = groups.filter((g) => !isHidden(g));
    const hiddenList = groups.filter(isHidden);
    const visibleColumns = activeGroups.flatMap((g) => g.columns);
    const totalColumns = groups.reduce((sum, g) => sum + g.columns.length, 0);

    const filteredRows = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return rows;
        return rows.filter((r) =>
            [r.employee_number, r.name, r.position_name].some((v) =>
                String(v ?? '')
                    .toLowerCase()
                    .includes(q),
            ),
        );
    }, [rows, search]);

    return (
        <>
            <Head title="Data Karyawan — Data Lengkap" />
            <EmployeeHeader />

            <div className="min-h-0 flex-1 bg-slate-50 p-6">
                <div className="flex h-full flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div className="shrink-0 space-y-3 border-b border-slate-100 bg-slate-50/50 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-bold text-slate-800">Data Lengkap Karyawan</h2>
                                <p className="mt-0.5 text-xs text-slate-400">
                                    {filteredRows.length} karyawan • {visibleColumns.length} dari {totalColumns} kolom ditampilkan • geser ke samping
                                    untuk melihat semua
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="relative">
                                    <Search className="absolute top-2.5 left-3 text-slate-300" size={16} />
                                    <input
                                        type="text"
                                        placeholder="Cari NRP / nama / jabatan..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        className="w-56 rounded-xl border border-slate-200 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-brand-500"
                                    />
                                </div>
                                <a
                                    href="/core-hr/employees/excel/template"
                                    title="Unduh template Excel kosong beserta petunjuk & daftar Jabatan"
                                    className={toolbarButton}
                                >
                                    <FileSpreadsheet size={16} />
                                    Template
                                </a>
                                <a
                                    href={`/core-hr/employees/excel/export${search.trim() ? `?q=${encodeURIComponent(search.trim())}` : ''}`}
                                    title={
                                        search.trim()
                                            ? `Unduh ${filteredRows.length} karyawan hasil pencarian — semua kolom, bisa diedit lalu diimpor kembali`
                                            : 'Unduh semua kolom — file ini bisa diedit lalu diimpor kembali'
                                    }
                                    className={toolbarButton}
                                >
                                    <Download size={16} />
                                    Export
                                </a>
                                <button onClick={() => setShowImport(true)} className={toolbarButton}>
                                    <Upload size={16} />
                                    Import
                                </button>
                                <button
                                    onClick={() => setShowAdd(true)}
                                    className="flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white shadow-lg shadow-brand-100 transition-all hover:bg-brand-700"
                                >
                                    <UserPlus size={16} />
                                    Tambah
                                </button>
                            </div>
                        </div>

                        <div>
                            <div className="flex flex-wrap gap-1.5">
                                {groups
                                    .filter((g) => g.locked)
                                    .map((g) => (
                                        <span
                                            key={g.id}
                                            title="Selalu aktif — berisi NRP & Nama, tidak bisa disembunyikan"
                                            className={`${chipStyle(true)} cursor-default border-slate-800 bg-slate-800`}
                                        >
                                            <Lock size={10} />
                                            {g.title}
                                        </span>
                                    ))}

                                {activeGroups
                                    .filter((g) => !g.locked)
                                    .map((g) => (
                                        <motion.button
                                            key={g.id}
                                            {...chipMotion}
                                            onClick={() => toggleGroup(g)}
                                            title={`${g.columns.length} kolom — klik untuk sembunyikan`}
                                            className={chipStyle(true)}
                                        >
                                            {g.title}
                                        </motion.button>
                                    ))}
                            </div>

                            {hiddenList.length > 0 && (
                                <div className="mt-2 flex flex-wrap items-center gap-1.5 border-t border-dashed border-slate-200 pt-2">
                                    <span className="mr-1 text-[10px] font-bold tracking-wide text-slate-400 uppercase">Tersembunyi:</span>
                                    {hiddenList.map((g) => (
                                        <motion.button
                                            key={g.id}
                                            {...chipMotion}
                                            onClick={() => toggleGroup(g)}
                                            title={`${g.columns.length} kolom — klik untuk tampilkan lagi`}
                                            className={chipStyle(false)}
                                        >
                                            <Plus size={10} />
                                            {g.title}
                                        </motion.button>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-auto">
                        <table className="border-collapse text-left text-sm whitespace-nowrap">
                            <thead className="sticky top-0 z-20">
                                <tr>
                                    {activeGroups.map((g, gi) => (
                                        <th
                                            key={g.id}
                                            colSpan={g.columns.length}
                                            className={`border-r border-white/20 px-4 py-2.5 text-[10px] font-bold tracking-widest text-white uppercase ${
                                                gi % 2 === 0 ? 'bg-slate-700' : 'bg-slate-600'
                                            }`}
                                        >
                                            {g.title}
                                        </th>
                                    ))}
                                    <th className="sticky right-0 z-30 bg-slate-700 px-4 py-2.5" />
                                </tr>
                                <tr>
                                    {visibleColumns.map((c) => (
                                        <th
                                            key={c.key}
                                            style={
                                                c.sticky
                                                    ? {
                                                          left: c.key === 'employee_number' ? 0 : NRP_WIDTH,
                                                      }
                                                    : undefined
                                            }
                                            className={`border-b border-slate-200 bg-slate-100 px-4 py-3 text-[10px] font-bold tracking-wide text-slate-500 uppercase ${
                                                c.sticky ? 'sticky left-0 z-10 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.1)]' : ''
                                            }`}
                                        >
                                            {c.label}
                                        </th>
                                    ))}
                                    <th className="sticky right-0 z-20 border-b border-slate-200 bg-slate-100 px-4 py-3 text-[10px] font-bold tracking-wide text-slate-500 uppercase shadow-[-2px_0_4px_-2px_rgba(0,0,0,0.1)]">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {filteredRows.map((row) => (
                                    <tr key={row.id} className="group transition-colors hover:bg-brand-50/40">
                                        {visibleColumns.map((c) => {
                                            // The latest contract's end date turns red within 2 months of ending.
                                            const isEndDate = c.key === 'last_contract_end_date';
                                            const endingSoon = isEndDate && row.last_contract_ending_soon === true;
                                            const daysLeft = row.last_contract_days_left;
                                            const endTitle =
                                                !isEndDate || typeof daysLeft !== 'number'
                                                    ? undefined
                                                    : daysLeft >= 0
                                                      ? endingSoon
                                                          ? `PKWT berakhir dalam ${daysLeft} hari`
                                                          : undefined
                                                      : `PKWT sudah berakhir ${Math.abs(daysLeft)} hari lalu`;

                                            return (
                                                <td
                                                    key={c.key}
                                                    style={
                                                        c.sticky
                                                            ? {
                                                                  left: c.key === 'employee_number' ? 0 : NRP_WIDTH,
                                                              }
                                                            : undefined
                                                    }
                                                    title={endTitle}
                                                    className={`px-4 py-3 ${endingSoon ? 'font-bold text-red-600' : 'text-slate-700'} ${
                                                        c.sticky
                                                            ? 'sticky left-0 z-10 bg-white font-bold shadow-[2px_0_4px_-2px_rgba(0,0,0,0.1)] group-hover:bg-brand-50'
                                                            : ''
                                                    }`}
                                                >
                                                    {displayValue(row[c.key])}
                                                    {c.key === 'name' && row.status === 'inactive' && (
                                                        <span className="ml-2 rounded-full bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold text-slate-500">
                                                            NONAKTIF
                                                        </span>
                                                    )}
                                                    {c.key === 'name' && !row.position_id && (
                                                        <span
                                                            title="Jabatannya belum ada di Plan SO, atau belum ditempatkan di Struktur Organisasi"
                                                            className="ml-2 rounded-full bg-amber-50 px-1.5 py-0.5 text-[9px] font-bold text-amber-700"
                                                        >
                                                            BELUM DITEMPATKAN
                                                        </span>
                                                    )}
                                                </td>
                                            );
                                        })}
                                        <td className="sticky right-0 z-10 bg-white px-4 py-3 shadow-[-2px_0_4px_-2px_rgba(0,0,0,0.1)] group-hover:bg-brand-50">
                                            <div className="flex items-center gap-1">
                                                <Link
                                                    href={`/core-hr/employees/${row.id}/profile`}
                                                    title="Buka & edit profil lengkap"
                                                    className="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-brand-50 hover:text-brand-600"
                                                >
                                                    <IdCard size={14} />
                                                </Link>
                                                <Link
                                                    href={`/core-hr/org-chart?location=${row.work_location_id ?? ''}${row.position_id ? `&focus=${row.position_id}` : ''}`}
                                                    title={
                                                        row.position_id
                                                            ? 'Lihat jabatannya di Struktur Organisasi'
                                                            : 'Tempatkan di Struktur Organisasi'
                                                    }
                                                    className="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-brand-50 hover:text-brand-600"
                                                >
                                                    <Network size={14} />
                                                </Link>
                                                <button
                                                    onClick={() => setExiting(row)}
                                                    title="Karyawan keluar"
                                                    className="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-rose-50 hover:text-rose-600"
                                                >
                                                    <LogOut size={14} />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {filteredRows.length === 0 && (
                            <p className="py-20 text-center text-sm font-medium text-slate-400">
                                {rows.length === 0 ? 'Belum ada data karyawan.' : 'Tidak ada karyawan yang cocok.'}
                            </p>
                        )}
                    </div>
                </div>
            </div>

            {showImport && (
                <EmployeeImportDialog
                    groups={groups}
                    currentCount={rows.length}
                    onClose={() => setShowImport(false)}
                    onImported={() => router.reload({ only: ['rows', 'groups'] })}
                />
            )}

            {showAdd && <AddEmployeeDialog onClose={() => setShowAdd(false)} />}

            {exiting && (
                <TerminateEmployeeDialog
                    employee={{
                        id: exiting.id,
                        employee_number: exiting.employee_number,
                        name: exiting.name,
                    }}
                    reasonOptions={reasonOptions}
                    onClose={() => setExiting(null)}
                />
            )}
        </>
    );
}
