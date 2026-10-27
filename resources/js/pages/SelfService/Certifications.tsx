import { Head } from '@inertiajs/react';
import { Award } from 'lucide-react';
import { AttachmentButton } from '@/components/attachment/AttachmentPicker';
import { CERT_TONE, daysText, type CertificationRow } from '@/lib/certifications';
import { fmtDate } from '@/lib/format';

/** Portal Karyawan → Sertifikat: the own licences and certificates, the soonest to run out first. */
export default function Certifications({ rows }: { rows: CertificationRow[] }) {
    return (
        <>
            <Head title="Sertifikat" />
            <div className="space-y-4">
                <h1 className="text-xl font-black text-slate-800">Sertifikat & Izin</h1>
                {rows.length === 0 ? (
                    <div className="py-10 text-center">
                        <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                            <Award size={26} />
                        </span>
                        <p className="mt-3 text-sm text-slate-500">Belum ada sertifikat yang dicatat HR untuk Anda.</p>
                    </div>
                ) : (
                    rows.map((r) => (
                        <section key={r.id} className="flex flex-wrap items-center justify-between gap-3 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div className="min-w-0">
                                <p className="font-bold text-slate-800">{r.type}</p>
                                <p className="text-xs text-slate-500">
                                    {r.number} {r.issuer ? `· ${r.issuer}` : ''} {r.expires_at ? `· berlaku sampai ${fmtDate(r.expires_at)}` : ''}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <AttachmentButton files={r.attachments} title={r.type ?? ''} noun="hal." />
                                <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${CERT_TONE[r.status]}`}>{daysText(r.days_left)}</span>
                            </div>
                        </section>
                    ))
                )}
                <p className="text-xs text-slate-400">Sudah memperpanjang? Kirim salinan sertifikat baru ke HR agar datanya diperbarui.</p>
            </div>
        </>
    );
}
