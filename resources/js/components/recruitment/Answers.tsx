import { ExternalLink, FileText } from 'lucide-react';
import { fmtDate } from '@/lib/format';
import { fileSize, type Answers as Item } from '@/lib/recruitment';

/** An application's answers, question by question; files open in a new tab. */
export function Answers({ items }: { items: Item[] }) {
    return (
        <dl className="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200">
            {items.map((j) => (
                <div key={j.field_id} className="grid gap-1 px-3 py-2.5 sm:grid-cols-[200px_minmax(0,1fr)] sm:gap-4">
                    <dt className="text-xs font-bold text-slate-500">{j.label}</dt>
                    <dd className="min-w-0 text-sm text-slate-800">
                        {j.file ? (
                            <a
                                href={j.file.url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex max-w-full items-center gap-1.5 font-bold text-brand-700 hover:underline"
                            >
                                <FileText size={15} className="shrink-0" />
                                <span className="truncate">{j.file.name}</span>
                                <span className="shrink-0 text-[11px] font-normal text-slate-400">{fileSize(j.file.size)}</span>
                            </a>
                        ) : j.value === null || j.value === '' ? (
                            <span className="text-slate-300">—</span>
                        ) : j.type === 'url' ? (
                            <a
                                href={j.value}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex max-w-full items-center gap-1 text-brand-700 hover:underline"
                            >
                                <span className="truncate">{j.value}</span>
                                <ExternalLink size={13} className="shrink-0" />
                            </a>
                        ) : j.type === 'email' ? (
                            <a href={`mailto:${j.value}`} className="break-all text-brand-700 hover:underline">
                                {j.value}
                            </a>
                        ) : j.type === 'date' ? (
                            fmtDate(j.value)
                        ) : (
                            <span className="break-words whitespace-pre-line">{j.value}</span>
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
