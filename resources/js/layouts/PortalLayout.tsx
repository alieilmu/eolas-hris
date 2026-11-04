import { useEffect, useState, type ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Award, ClipboardCheck, HandCoins, Home, KeyRound, LogOut, Menu, ReceiptText, Smartphone, UserPen, X } from 'lucide-react';
import { AppGuard } from '@/components/AppGuard';
import { BrandLogo, brandLine, useBranding } from '@/components/BrandLogo';
import { FlashToast } from '@/components/FlashToast';
import { ClockQueueBar } from '@/components/time/ClockQueueBar';
import { forgetClock } from '@/lib/clockQueue';

// module: an add-on package (Modules) the license must include; none = Core.
const NAV: { href: string; label: string; short?: string; icon: typeof Home; module?: 'talent' | 'lms' }[] = [
    { href: '/self-service', label: 'Beranda', icon: Home },
    { href: '/self-service/payslips', label: 'Slip Gaji', short: 'Slip', icon: ReceiptText },
    { href: '/self-service/loans', label: 'Pinjaman', icon: HandCoins },
    { href: '/self-service/certifications', label: 'Sertifikat', icon: Award },
    { href: '/self-service/profile', label: 'Data Saya', icon: UserPen },
];

/** On the phone's bottom bar; the rest sits under "Lainnya". */
const BAR = 4;

/**
 * Portal Karyawan: a simple top bar instead of the admin sidebar. On a phone the menu is a bar
 * at the bottom, within reach of the thumb, like an app.
 */
export default function PortalLayout({ children }: { children: ReactNode }) {
    const { auth, modules } = usePage().props;
    const url = usePage().url.split('?')[0];
    const [more, setMore] = useState(false);
    useEffect(() => setMore(false), [url]);
    // Persetujuan: for a superior with pengajuan waiting (and while on the page).
    const approvals = auth.approvals > 0 || url.startsWith('/approvals') ? [{ href: '/approvals', label: 'Persetujuan', short: 'Setujui', icon: ClipboardCheck, badge: auth.approvals }] : [];
    const items: ((typeof NAV)[number] & { badge?: number })[] = NAV.filter((item) => !item.module || modules.includes(item.module));
    // Waiting decisions come right after Beranda, so they stay on the bar.
    const nav = [items[0], ...approvals, ...items.slice(1)];
    const bar = nav.length > BAR + 1 ? nav.slice(0, BAR) : nav;
    const rest = nav.length > BAR + 1 ? nav.slice(BAR) : [];
    const user = auth.user;
    const branding = useBranding();
    const active = (href: string) => (href === '/self-service' ? url === href : url.startsWith(href));

    return (
        <div className="flex min-h-screen flex-col overflow-x-clip bg-slate-50">
            <header className="sticky top-0 z-40 border-b-[3px] border-accent-400 bg-white pt-[env(safe-area-inset-top)]">
                <div className="mx-auto flex h-14 max-w-5xl items-center gap-2 px-3 sm:h-16 sm:gap-3 sm:px-4">
                    <Link href="/self-service" className="flex min-w-0 items-center gap-2 sm:gap-3">
                        <BrandLogo className="h-7 sm:h-8" />
                        <div className="min-w-0 border-l border-slate-200 pl-2 leading-tight sm:pl-3">
                            <p className="truncate text-sm font-black tracking-tight text-brand-700">Portal Karyawan</p>
                            <p className="hidden truncate text-[10px] font-semibold text-slate-400 sm:block">{brandLine(branding)}</p>
                        </div>
                    </Link>
                    <nav className="ml-4 hidden items-center gap-1 sm:flex">
                        {nav.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-bold whitespace-nowrap ${
                                    active(item.href) ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'
                                }`}
                            >
                                <item.icon size={15} />
                                {item.label}
                                {!!item.badge && <span className="rounded-full bg-amber-400 px-1.5 text-[11px] font-black text-brand-950">{item.badge}</span>}
                            </Link>
                        ))}
                    </nav>
                    <div className="ml-auto flex shrink-0 items-center gap-0.5 sm:gap-1">
                        {user && <span className="mr-2 hidden max-w-48 truncate text-sm font-bold text-slate-600 lg:block">{user.name}</span>}
                        <Link
                            href="/account/app"
                            title="Keamanan aplikasi HP"
                            aria-label="Keamanan aplikasi HP"
                            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-brand-600"
                        >
                            <Smartphone size={18} />
                        </Link>
                        <Link
                            href="/account/password"
                            title="Ganti password"
                            aria-label="Ganti password"
                            className="hidden rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-brand-600 sm:block"
                        >
                            <KeyRound size={18} />
                        </Link>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            // The Absen HP state kept for the offline page goes with the account.
                            onClick={() => void forgetClock()}
                            title="Keluar"
                            aria-label="Keluar"
                            className="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                        >
                            <LogOut size={18} />
                        </Link>
                    </div>
                </div>
            </header>
            <main className="mx-auto w-full max-w-5xl min-w-0 flex-1 px-3 pt-4 pb-[calc(5.5rem+env(safe-area-inset-bottom))] sm:px-4 sm:py-6">
                <ClockQueueBar />
                {children}
            </main>

            {/* Phone: the menu at the bottom. */}
            <nav className="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur sm:hidden">
                <div className="flex">
                    {bar.map((item) => (
                        <BarLink key={item.href} href={item.href} label={item.short ?? item.label} icon={item.icon} badge={item.badge} active={active(item.href)} />
                    ))}
                    {rest.length > 0 && (
                        <button
                            onClick={() => setMore((v) => !v)}
                            className={`flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-bold ${rest.some((i) => active(i.href)) || more ? 'text-brand-700' : 'text-slate-400'}`}
                        >
                            <Menu size={21} />
                            Lainnya
                        </button>
                    )}
                </div>
            </nav>
            {more && (
                <div className="fixed inset-0 z-30 bg-slate-900/30 sm:hidden" onClick={() => setMore(false)}>
                    <div
                        className="absolute inset-x-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] rounded-3xl bg-white p-2 shadow-xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="flex items-center justify-between px-3 pt-1 pb-2">
                            <p className="text-xs font-bold text-slate-400">Menu lainnya</p>
                            <button onClick={() => setMore(false)} aria-label="Tutup" className="rounded-lg p-1 text-slate-400">
                                <X size={16} />
                            </button>
                        </div>
                        {[...rest, { href: '/account/password', label: 'Ganti password', icon: KeyRound }].map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`flex items-center gap-3 rounded-2xl px-3 py-3 text-sm font-bold ${active(item.href) ? 'bg-brand-50 text-brand-700' : 'text-slate-600'}`}
                            >
                                <item.icon size={18} />
                                {item.label}
                            </Link>
                        ))}
                    </div>
                </div>
            )}
            <FlashToast aboveBar />
            <AppGuard />
        </div>
    );
}

function BarLink({ href, label, icon: Icon, badge, active }: { href: string; label: string; icon: typeof Home; badge?: number; active: boolean }) {
    return (
        <Link href={href} className={`relative flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-bold ${active ? 'text-brand-700' : 'text-slate-400'}`}>
            <span className={`rounded-full px-4 py-0.5 ${active ? 'bg-brand-50' : ''}`}>
                <Icon size={21} />
            </span>
            {label}
            {!!badge && <span className="absolute top-1 left-1/2 ml-2 rounded-full bg-amber-400 px-1.5 text-[10px] font-black text-brand-950">{badge}</span>}
        </Link>
    );
}
