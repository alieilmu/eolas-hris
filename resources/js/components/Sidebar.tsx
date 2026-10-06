import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, KeyRound, LogOut, PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import type { NavItem, NavModule } from '@/types';

interface SidebarProps {
    modules: NavModule[];
}

const CLOSED_KEY = 'app.sidebarClosed';

export function Sidebar({ modules: allModules }: SidebarProps) {
    const { url, props } = usePage();
    const user = props.auth.user;
    // Settings → Akun & Akses: only the menus this account may open.
    const allowed = (item: NavItem) =>
        !item.menu || (item.menu === 'settings' ? props.auth.can.manageUsers : props.auth.menus?.[item.menu] !== undefined);
    const modules = allModules
        .map((m) => ({ ...m, items: m.items.filter(allowed).map((i) => (i.children ? { ...i, children: i.children.filter(allowed) } : i)) }))
        .filter((m) => m.items.length > 0);
    // Closed: a narrow rail of icons, so the page gets the room; remembered in this browser.
    const [closed, setClosed] = useState(() => {
        try {
            return localStorage.getItem(CLOSED_KEY) === '1';
        } catch {
            return false;
        }
    });
    const toggle = (next: boolean) => {
        setClosed(next);
        try {
            localStorage.setItem(CLOSED_KEY, next ? '1' : '0');
        } catch {
            // Storage unavailable: lasts for this visit only.
        }
    };
    const [expandedModules, setExpandedModules] = useState<Record<string, boolean>>(() => Object.fromEntries(modules.map((m) => [m.id, true])));

    const toggleModule = (moduleId: string) => {
        setExpandedModules((prev) => ({ ...prev, [moduleId]: !prev[moduleId] }));
    };

    const matches = (href?: string) => !!href && (url === href || url.startsWith(`${href}/`) || url.startsWith(`${href}?`));
    // The most specific menu wins: a menu under another's path is active alone, not its parent.
    const allHrefs = modules.flatMap((m) => m.items.flatMap((i) => [i.href, ...(i.children ?? []).map((c) => c.href)])).filter(Boolean) as string[];
    const activeHref = allHrefs.filter(matches).sort((a, b) => b.length - a.length)[0];
    const isActive = (href?: string) => !!href && href === activeHref;

    const renderItem = (item: NavItem) => {
        const ItemIcon = item.icon;
        const active = isActive(item.href);
        const className = `flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-all ${
            item.disabled
                ? 'cursor-not-allowed text-brand-400/60'
                : active
                  ? 'bg-accent-400 font-bold text-brand-950 shadow-sm shadow-black/20'
                  : 'text-brand-200 hover:bg-white/5 hover:text-white'
        }`;

        if (item.children) {
            return (
                <div key={item.id}>
                    <span className="flex w-full items-center gap-2.5 px-3 py-2 text-sm font-bold text-brand-100">
                        <ItemIcon size={15} />
                        {item.label}
                    </span>
                    <div className="ml-3 space-y-0.5 border-l border-white/10 pl-2">{item.children.map(renderItem)}</div>
                </div>
            );
        }
        if (item.disabled || !item.href) {
            return (
                <span key={item.id} className={className} aria-disabled="true">
                    <ItemIcon size={15} />
                    {item.label}
                </span>
            );
        }
        return (
            <Link key={item.id} href={item.href} prefetch className={className}>
                <ItemIcon size={15} />
                {item.label}
            </Link>
        );
    };

    if (closed) {
        const railItems = (items: NavItem[]): NavItem[] => items.flatMap((i) => (i.children ? railItems(i.children) : [i]));
        return (
            <aside className="flex h-full w-16 shrink-0 flex-col bg-brand-900 text-white">
                <div className="flex h-16 shrink-0 items-center justify-center border-b-[3px] border-accent-400 bg-white">
                    <button
                        onClick={() => toggle(false)}
                        title="Buka sidebar"
                        aria-label="Buka sidebar"
                        className="rounded-lg p-2 text-brand-600 hover:bg-brand-50"
                    >
                        <PanelLeftOpen size={20} />
                    </button>
                </div>
                <nav className="scrollbar-hide flex-1 space-y-3 overflow-y-auto px-2 py-4">
                    {modules.map((module) => {
                        const ModuleIcon = module.icon;
                        return (
                            <div key={module.id} className="space-y-1 border-b border-white/10 pb-3 last:border-0">
                                <ModuleIcon size={16} className="mx-auto mb-1 text-accent-400" aria-label={module.label} />
                                {railItems(module.items).map((item) => {
                                    const ItemIcon = item.icon;
                                    const className = `flex h-10 w-full items-center justify-center rounded-lg transition-all ${
                                        item.disabled
                                            ? 'cursor-not-allowed text-brand-400/60'
                                            : isActive(item.href)
                                              ? 'bg-accent-400 text-brand-950 shadow-sm shadow-black/20'
                                              : 'text-brand-200 hover:bg-white/5 hover:text-white'
                                    }`;
                                    return item.disabled || !item.href ? (
                                        <span key={item.id} title={item.label} className={className} aria-disabled="true">
                                            <ItemIcon size={17} />
                                        </span>
                                    ) : (
                                        <Link
                                            key={item.id}
                                            href={item.href}
                                            prefetch
                                            title={item.label}
                                            aria-label={item.label}
                                            className={className}
                                        >
                                            <ItemIcon size={17} />
                                        </Link>
                                    );
                                })}
                            </div>
                        );
                    })}
                </nav>
                {user && (
                    <div className="flex shrink-0 flex-col items-center gap-1 border-t border-white/10 py-3">
                        <div
                            title={`${user.name} (${user.role_label})`}
                            className="flex h-9 w-9 items-center justify-center rounded-xl bg-accent-400 font-bold text-brand-950 uppercase"
                        >
                            {user.name.charAt(0)}
                        </div>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            title="Keluar"
                            aria-label="Keluar"
                            className="flex h-9 w-9 items-center justify-center rounded-lg text-brand-200 hover:bg-rose-500/15 hover:text-rose-200"
                        >
                            <LogOut size={16} />
                        </Link>
                    </div>
                )}
            </aside>
        );
    }

    return (
        <aside className="flex h-full w-72 shrink-0 flex-col bg-brand-900 text-white">
            <div className="flex h-16 shrink-0 items-center gap-3 border-b-[3px] border-accent-400 bg-white pr-2 pl-5">
                <img src="/images/riung-logo.webp" alt="Riung" className="h-8 w-auto" />
                <div className="min-w-0 flex-1 border-l border-slate-200 pl-3 leading-tight">
                    <h1 className="text-sm font-black tracking-tight text-brand-700">HR Puspita</h1>
                    <p className="truncate text-[10px] font-semibold text-riung-gray">PT Riung Mitra Lestari</p>
                </div>
                <button
                    onClick={() => toggle(true)}
                    title="Tutup sidebar"
                    aria-label="Tutup sidebar"
                    className="shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-600"
                >
                    <PanelLeftClose size={18} />
                </button>
            </div>

            <nav className="scrollbar-hide flex-1 space-y-1 overflow-y-auto px-3 py-4">
                {modules.map((module) => {
                    const isExpanded = expandedModules[module.id];
                    const ModuleIcon = module.icon;

                    return (
                        <div key={module.id}>
                            <button
                                onClick={() => toggleModule(module.id)}
                                className="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-sm font-bold text-brand-100 transition-colors hover:bg-white/5"
                                aria-expanded={isExpanded}
                            >
                                <span className="flex items-center gap-3">
                                    <ModuleIcon size={18} className="text-accent-400" />
                                    {module.label}
                                </span>
                                <ChevronDown size={16} className={`text-brand-300 transition-transform ${isExpanded ? '' : '-rotate-90'}`} />
                            </button>

                            {isExpanded && <div className="mt-1 ml-4 space-y-0.5 border-l border-white/10 pl-4">{module.items.map(renderItem)}</div>}
                        </div>
                    );
                })}
            </nav>

            {user && (
                <div className="shrink-0 border-t border-white/10 p-3">
                    <div className="flex items-center gap-3 px-2 py-2">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-accent-400 font-bold text-brand-950 uppercase">
                            {user.name.charAt(0)}
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-bold text-white">{user.name}</p>
                            <p className="text-[11px] font-bold tracking-wide text-accent-300 uppercase">{user.role_label}</p>
                        </div>
                    </div>
                    <Link
                        href="/akun/password"
                        className="mt-1 flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-brand-200 transition-all hover:bg-white/5 hover:text-white"
                    >
                        <KeyRound size={15} />
                        Ganti Password
                    </Link>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-brand-200 transition-all hover:bg-rose-500/15 hover:text-rose-200"
                    >
                        <LogOut size={15} />
                        Keluar
                    </Link>
                </div>
            )}
        </aside>
    );
}
