import { Head, Link } from '@inertiajs/react';
import { FileText, KeyRound, ReceiptText } from 'lucide-react';
import { SlipView, type Slip } from '@/components/compensation/SlipView';
import { rupiah } from '@/lib/compensation';
import { fmtDate } from '@/lib/format';

interface Props {
    slips: { id: number; period: string; label: string; pay_date: string | null; take_home_pay: number }[];
    slip: (Slip & { id: number }) | null;
    password: boolean;
}

/** Portal Karyawan → Slip Gaji: the own payslips of approved payroll runs, and a PDF of each. */
export default function Payslips({ slips, slip, password }: Props) {
    return (
        <>
            <Head title="Slip Gaji" />
            <div>
                <h1 className="text-xl font-black text-slate-800">Slip Gaji</h1>
                {slips.length === 0 ? (
                    <div className="mt-10 text-center">
                        <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                            <ReceiptText size={26} />
                        </span>
                        <p className="mt-3 text-sm text-slate-500">Belum ada slip gaji. Slip muncul di sini setelah proses gaji disetujui HR.</p>
                    </div>
                ) : (
                    <div className="mt-4 grid gap-4 lg:grid-cols-[220px_1fr]">
                        <nav className="flex gap-2 overflow-x-auto lg:flex-col">
                            {slips.map((s) => (
                                <Link
                                    key={s.id}
                                    href={`/self-service/payslips?slip=${s.id}`}
                                    preserveScroll
                                    className={`shrink-0 rounded-xl border px-3 py-2 text-sm ${
                                        s.id === slip?.id ? 'border-brand-300 bg-brand-50 font-bold text-brand-800' : 'border-slate-200 bg-white text-slate-600'
                                    }`}
                                >
                                    {s.label}
                                    <span className="block text-xs font-normal text-slate-400">Rp {rupiah(s.take_home_pay)}</span>
                                </Link>
                            ))}
                        </nav>
                        {slip && (
                            <div className="min-w-0 space-y-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-sm text-slate-500">
                                        {slips.find((s) => s.id === slip.id)?.pay_date ? `Dibayar ${fmtDate(slips.find((s) => s.id === slip.id)!.pay_date)}` : ''}
                                    </p>
                                    <a
                                        href={`/self-service/payslips/${slip.id}/pdf`}
                                        className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white hover:bg-brand-700"
                                    >
                                        <FileText size={15} /> Unduh PDF
                                    </a>
                                </div>
                                {password && (
                                    <p className="flex items-center gap-2 rounded-xl bg-slate-100 px-3 py-2 text-xs text-slate-600">
                                        <KeyRound size={14} /> PDF dibuka dengan password tanggal lahir Anda (ddmmyyyy), mis. 17081990.
                                    </p>
                                )}
                                <SlipView slip={slip} />
                            </div>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}
