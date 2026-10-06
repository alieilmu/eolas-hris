import { useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Building, PanelRightClose, PanelRightOpen, PencilRuler, Users } from 'lucide-react';
import type { LocationTab } from '@/lib/orgTree';

/** "organisasi": arrange the structure; "mpp": fill it with people. */
export type OrgMode = 'organisasi' | 'mpp';

const MODES: { id: OrgMode; label: string; hint: string; icon: typeof Users }[] = [
    { id: 'organisasi', label: 'Mode Organisasi', hint: 'Menyusun struktur: jabatan, unit, Sub Lokasi dan atasannya', icon: PencilRuler },
    { id: 'mpp', label: 'Mode MPP', hint: 'Mengisi struktur dengan karyawan, mutasi dan copot', icon: Users },
];

/** Title bar with the switch between the two modes; switching keeps the page (no reload). */
export function OrgHeader({ mode, onMode }: { mode: OrgMode; onMode: (m: OrgMode) => void }) {
    return (
        <header className="flex h-16 shrink-0 items-center justify-between gap-4 border-b-[3px] border-accent-400 bg-white px-8">
            <h1 className="text-xl font-bold tracking-tight text-slate-800">Struktur Organisasi</h1>
            <nav className="flex items-center gap-1 rounded-xl bg-slate-100 p-1" aria-label="Mode">
                {MODES.map(({ id, label, hint, icon: Icon }) => (
                    <button
                        key={id}
                        onClick={() => id !== mode && onMode(id)}
                        title={hint}
                        aria-pressed={mode === id}
                        className={`flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-sm font-bold transition-all ${
                            mode === id ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
                        }`}
                    >
                        <Icon size={15} />
                        {label}
                    </button>
                ))}
            </nav>
        </header>
    );
}

/** An on/off view setting, remembered in this browser. */
export function useStoredSwitch(key: string): [boolean, (v: boolean) => void] {
    const [value, setValue] = useState(() => {
        try {
            return localStorage.getItem(key) === '1';
        } catch {
            return false;
        }
    });
    const set = (v: boolean) => {
        setValue(v);
        try {
            localStorage.setItem(key, v ? '1' : '0');
        } catch {
            // Storage unavailable: the setting lasts for this visit only.
        }
    };
    return [value, set];
}

export function ViewSwitch({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            className={`flex items-center gap-2 rounded-xl border px-2.5 py-1.5 text-xs font-bold transition-colors ${
                checked ? 'border-brand-200 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'
            }`}
        >
            <span className={`relative h-4 w-7 rounded-full transition-colors ${checked ? 'bg-brand-600' : 'bg-slate-300'}`}>
                <span className={`absolute top-0.5 h-3 w-3 rounded-full bg-white shadow transition-all ${checked ? 'left-3.5' : 'left-0.5'}`} />
            </span>
            {label}
        </button>
    );
}

/** The right panel's close button. */
export function CloseAsideButton({ onClick }: { onClick: () => void }) {
    return (
        <button
            onClick={onClick}
            title="Tutup panel"
            aria-label="Tutup panel"
            className="shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-600"
        >
            <PanelRightClose size={17} />
        </button>
    );
}

/** What is left of the right panel while it is closed: a strip to open it again. */
export function AsideRail({ label, onOpen }: { label: string; onOpen: () => void }) {
    return (
        <button
            onClick={onOpen}
            title={`Buka panel ${label}`}
            aria-label={`Buka panel ${label}`}
            className="flex w-10 shrink-0 flex-col items-center gap-3 border-l border-slate-200 bg-white py-3 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
        >
            <PanelRightOpen size={17} />
            <span className="text-[11px] font-bold tracking-wide [writing-mode:vertical-rl]">{label}</span>
        </button>
    );
}

interface TabsProps {
    locations: LocationTab[];
    activeId: number | undefined;
    mode: OrgMode;
    /** What the small number on each tab counts. */
    badge: (l: LocationTab) => ReactNode;
}

/** One tab per work location: each has its own organisation. The mode stays when switching. */
export function LocationTabs({ locations, activeId, mode, badge }: TabsProps) {
    return (
        <div className="flex shrink-0 flex-wrap items-center gap-1.5 border-b border-slate-200 bg-white px-6 py-2.5">
            {locations.map((l) => {
                const active = l.id === activeId;
                return (
                    <Link
                        key={l.id}
                        href={`/manpower/org-structure?lokasi=${l.id}&mode=${mode}`}
                        preserveScroll
                        preserveState
                        className={`flex items-center gap-2 rounded-xl px-3 py-1.5 text-sm font-bold transition-all ${
                            active ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'
                        }`}
                    >
                        {l.type === 'Head Office' && <Building size={13} className={active ? 'text-accent-300' : 'text-slate-400'} />}
                        {l.name}
                        <span className={`rounded-full px-1.5 text-[10px] tabular-nums ${active ? 'bg-white/20' : 'bg-slate-100'}`}>{badge(l)}</span>
                    </Link>
                );
            })}
        </div>
    );
}
