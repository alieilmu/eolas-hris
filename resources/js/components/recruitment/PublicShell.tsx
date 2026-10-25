import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { BrandLogo, useBranding } from '@/components/BrandLogo';

/** The public pages (Lowongan): the company's logo and name on top, nothing of the admin app. */
export function PublicShell({ children }: { children: ReactNode }) {
    const b = useBranding();
    return (
        <div className="flex min-h-screen flex-col bg-slate-50">
            <header className="border-b-[3px] border-accent-400 bg-white">
                <div className="mx-auto flex h-16 max-w-4xl items-center gap-3 px-4">
                    <Link href="/careers" className="flex min-w-0 items-center gap-3">
                        <BrandLogo className="h-9" />
                        <span className="min-w-0 border-l border-slate-200 pl-3 leading-tight">
                            <span className="block truncate text-sm font-black text-brand-700">{b.company_name ?? b.app_name}</span>
                            <span className="block truncate text-[11px] font-semibold text-slate-400">
                                Karier{b.tagline ? ` · ${b.tagline}` : ''}
                            </span>
                        </span>
                    </Link>
                </div>
            </header>
            <main className="w-full flex-1">{children}</main>
            <footer className="border-t border-slate-200 bg-white px-4 py-6 text-center text-xs text-slate-400">
                © {new Date().getFullYear()} {b.company_name ?? b.app_name}. Data lamaran hanya dipakai untuk proses rekrutmen.
            </footer>
        </div>
    );
}
