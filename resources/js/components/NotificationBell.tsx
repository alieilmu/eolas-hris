import { useCallback, useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    Bell,
    BellRing,
    ClipboardCheck,
    ClipboardList,
    CalendarHeart,
    CheckCheck,
    Loader2,
    Palmtree,
    Plane,
    Stethoscope,
    UserPen,
    UserRoundPlus,
    X,
    type LucideIcon,
} from 'lucide-react';
import { postJson } from '@/lib/http';

interface Item {
    id: string;
    type: 'data-change-requests' | 'claim' | 'business_trip' | 'leave' | 'long-service-leave' | 'applicants' | 'workforce_planning' | 'workforce_planning-result' | null;
    title: string;
    body: string;
    url: string | null;
    read: boolean;
    created_at: string | null;
}

const POLL_MS = 60_000;

const ICON: Record<NonNullable<Item['type']>, { icon: LucideIcon; tone: string }> = {
    'data-change-requests': { icon: UserPen, tone: 'bg-sky-50 text-sky-600' },
    claim: { icon: Stethoscope, tone: 'bg-rose-50 text-rose-600' },
    business_trip: { icon: Plane, tone: 'bg-brand-50 text-brand-600' },
    leave: { icon: Palmtree, tone: 'bg-emerald-50 text-emerald-600' },
    'long-service-leave': { icon: CalendarHeart, tone: 'bg-violet-50 text-violet-600' },
    applicants: { icon: UserRoundPlus, tone: 'bg-amber-50 text-amber-600' },
    workforce_planning: { icon: ClipboardList, tone: 'bg-indigo-50 text-indigo-600' },
    'workforce_planning-result': { icon: ClipboardCheck, tone: 'bg-indigo-50 text-indigo-600' },
};

/** "baru saja", "5 mnt lalu", "3 jam lalu", "2 hari lalu", else the date. */
function ago(iso: string | null): string {
    if (!iso) return '';
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'baru saja';
    if (s < 3600) return `${Math.floor(s / 60)} mnt lalu`;
    if (s < 86400) return `${Math.floor(s / 3600)} jam lalu`;
    if (s < 7 * 86400) return `${Math.floor(s / 86400)} hari lalu`;
    return new Date(iso).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

/**
 * The bell in the admin sidebar: pengajuan from the employee portal waiting for this account.
 * Checks every minute; a new one pops up in the corner (and as a browser notification when
 * allowed and the tab is in the background). The list opens beside the sidebar.
 */
export function NotificationBell({ panelLeft, dark = false }: { panelLeft: number; dark?: boolean }) {
    const shared = usePage().props.auth.notifications;
    const [unread, setUnread] = useState(shared);
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<Item[] | null>(null);
    const [popup, setPopup] = useState<Item | null>(null);
    const [permission, setPermission] = useState(() => ('Notification' in window ? Notification.permission : 'denied'));
    const lastSeen = useRef<string | null>(null);
    const panel = useRef<HTMLDivElement>(null);

    // Every page visit brings the count along.
    useEffect(() => setUnread(shared), [shared]);

    // The tab title shows the count, e.g. "(3) Deklarasi - HR Puspita"; again after each visit sets its own title.
    useEffect(() => {
        const apply = () => {
            const base = document.title.replace(/^\(\d+\)\s*/, '');
            document.title = unread > 0 ? `(${unread}) ${base}` : base;
        };
        apply();
        // Inertia writes the title after the page renders: put the count back whenever it does.
        const observer = new MutationObserver(() => {
            if (unread > 0 && !/^\(\d+\)/.test(document.title)) apply();
        });
        observer.observe(document.head, { subtree: true, childList: true, characterData: true });
        return () => observer.disconnect();
    }, [unread]);

    const load = useCallback(async () => {
        const res = await fetch('/notifications', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return;
        const data = (await res.json()) as { items: Item[]; unread: number };
        setItems(data.items);
        setUnread(data.unread);
    }, []);

    // Polling: a new unread one (not seen before) pops up.
    useEffect(() => {
        let stop = false;
        const check = async () => {
            const res = await fetch('/notifications/quantity', { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).catch(() => null);
            if (!res?.ok || stop) return;
            const data = (await res.json()) as { unread: number; latest: Item | null };
            setUnread(data.unread);
            const newest = data.latest;
            if (newest && lastSeen.current !== null && newest.id !== lastSeen.current) {
                setPopup(newest);
                if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
                    const n = new Notification(newest.title, { body: newest.body, tag: newest.id });
                    n.onclick = () => {
                        window.focus();
                        if (newest.url) router.visit(newest.url);
                    };
                }
                if (open) load();
            }
            lastSeen.current = newest?.id ?? '';
        };
        check();
        const timer = setInterval(check, POLL_MS);
        return () => {
            stop = true;
            clearInterval(timer);
        };
    }, [open, load]);

    useEffect(() => {
        if (!popup) return;
        const t = setTimeout(() => setPopup(null), 8000);
        return () => clearTimeout(t);
    }, [popup]);

    // Close on Escape or a click outside.
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        const onDown = (e: MouseEvent) => {
            if (!panel.current?.contains(e.target as Node) && !(e.target as HTMLElement).closest('[data-bell]')) setOpen(false);
        };
        window.addEventListener('keydown', onKey);
        window.addEventListener('mousedown', onDown);
        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('mousedown', onDown);
        };
    }, [open]);

    const toggle = () => {
        if (!open) load();
        setOpen(!open);
    };

    const go = (item: Item) => {
        setOpen(false);
        setPopup(null);
        if (!item.read) {
            postJson<{ unread: number }>(`/notifications/${item.id}/read`, {})
                .then((d) => setUnread(d.unread))
                .catch(() => {});
            setItems((list) => list?.map((i) => (i.id === item.id ? { ...i, read: true } : i)) ?? null);
        }
        if (item.url) router.visit(item.url);
    };

    const readAll = async () => {
        await postJson('/notifications/read-all', {}).catch(() => {});
        setUnread(0);
        setItems((list) => list?.map((i) => ({ ...i, read: true })) ?? null);
    };

    const askPermission = () => Notification.requestPermission().then(setPermission);

    return (
        <>
            <button
                data-bell
                onClick={toggle}
                title={unread ? `${unread} pengajuan menunggu` : 'Notifikasi'}
                aria-label={unread ? `Notifikasi, ${unread} belum dibaca` : 'Notifikasi'}
                aria-expanded={open}
                className={`relative shrink-0 rounded-lg p-1.5 ${
                    dark ? 'text-brand-200 hover:bg-white/5 hover:text-white' : 'text-slate-400 hover:bg-slate-100 hover:text-brand-600'
                } ${open ? (dark ? 'bg-white/10 text-white' : 'bg-slate-100 text-brand-600') : ''}`}
            >
                {unread > 0 ? <BellRing size={18} /> : <Bell size={18} />}
                {unread > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] leading-none font-bold text-white ring-2 ring-white">
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </button>

            {open && (
                <div
                    ref={panel}
                    role="dialog"
                    aria-label="Notifikasi"
                    style={{ left: panelLeft }}
                    className="fixed top-2 z-[65] flex max-h-[calc(100dvh-1rem)] w-[min(400px,calc(100vw-1rem))] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white text-slate-700 shadow-2xl max-sm:right-2 max-sm:left-2! max-sm:w-auto"
                >
                    <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-3">
                        <p className="flex-1 text-sm font-bold text-slate-800">
                            Notifikasi
                            {unread > 0 && (
                                <span className="ml-1.5 rounded-full bg-rose-50 px-1.5 py-0.5 text-[11px] text-rose-600">{unread} baru</span>
                            )}
                        </p>
                        {unread > 0 && (
                            <button
                                onClick={readAll}
                                className="flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-bold text-brand-600 hover:bg-brand-50"
                            >
                                <CheckCheck size={14} />
                                Tandai semua dibaca
                            </button>
                        )}
                        <button onClick={() => setOpen(false)} aria-label="Tutup" className="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
                            <X size={16} />
                        </button>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {items === null ? (
                            <p className="flex items-center justify-center gap-2 py-10 text-sm text-slate-400">
                                <Loader2 size={16} className="animate-spin" />
                                Memuat…
                            </p>
                        ) : items.length === 0 ? (
                            <div className="px-6 py-10 text-center">
                                <Bell size={28} className="mx-auto text-slate-200" />
                                <p className="mt-2 text-sm font-bold text-slate-500">Belum ada notifikasi</p>
                                <p className="text-xs text-slate-400">Pengajuan dari Portal Karyawan akan muncul di sini.</p>
                            </div>
                        ) : (
                            <ul className="divide-y divide-slate-100">
                                {items.map((item) => (
                                    <li key={item.id}>
                                        <NotifRow item={item} onClick={() => go(item)} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {permission === 'default' && (
                        <button
                            onClick={askPermission}
                            className="border-t border-slate-100 bg-slate-50 px-4 py-2.5 text-left text-xs font-bold text-brand-600 hover:bg-brand-50"
                        >
                            Aktifkan notifikasi browser — tetap muncul saat tab ini tidak dibuka
                        </button>
                    )}
                </div>
            )}

            {popup && !open && (
                <div
                    role="status"
                    className="fixed right-6 bottom-24 z-[70] w-[min(380px,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
                >
                    <p className="flex items-center gap-1.5 border-b border-slate-100 bg-accent-50 px-4 py-1.5 text-[11px] font-bold tracking-wide text-brand-800 uppercase">
                        <BellRing size={13} />
                        Pengajuan baru
                        <button onClick={() => setPopup(null)} aria-label="Tutup" className="ml-auto text-slate-400 hover:text-slate-600">
                            <X size={14} />
                        </button>
                    </p>
                    <NotifRow item={popup} onClick={() => go(popup)} />
                </div>
            )}
        </>
    );
}

function NotifRow({ item, onClick }: { item: Item; onClick: () => void }) {
    const look = item.type ? ICON[item.type] : { icon: Bell, tone: 'bg-slate-100 text-slate-500' };
    const Icon = look.icon;
    return (
        <button
            onClick={onClick}
            className={`flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-slate-50 ${item.read ? 'opacity-60' : ''}`}
        >
            <span className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl ${look.tone}`}>
                <Icon size={16} />
            </span>
            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-2">
                    <span className="flex-1 truncate text-sm font-bold text-slate-800">{item.title}</span>
                    {!item.read && <span className="h-2 w-2 shrink-0 rounded-full bg-rose-500" aria-label="belum dibaca" />}
                </span>
                <span className="mt-0.5 line-clamp-2 block text-xs text-slate-500">{item.body}</span>
                <span className="mt-1 block text-[11px] text-slate-400">{ago(item.created_at)}</span>
            </span>
        </button>
    );
}
