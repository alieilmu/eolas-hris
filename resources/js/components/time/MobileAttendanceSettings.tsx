import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Loader2, LocateFixed, MapPin, Plus, Trash2 } from 'lucide-react';
import { smallInput } from '@/components/time/ui';

export interface MobileLocation {
    id: number;
    name: string;
    mode: 'off' | 'reject' | 'flag';
    geofences: { id?: number; name: string; latitude: number | string; longitude: number | string; radius_m: number | string }[];
}

const BASE = '/time/settings';
const num = (v: number | string) => (String(v).trim() === '' ? null : Number(String(v).replace(',', '.')));

/**
 * Pengaturan Kehadiran → Absen HP: per work location, whether employees clock in from the
 * phone, the circles (geofences) they may do it in, and what happens outside them; and how
 * long the selfies are kept.
 */
export function MobileAttendanceSettings({ locations, modes, retention }: { locations: MobileLocation[]; modes: Record<string, string>; retention: number }) {
    const [days, setDays] = useState(retention);
    return (
        <div className="space-y-4">
            {locations.map((l) => (
                // A new key once saved: the card starts again from what the server keeps.
                <LocationCard key={`${l.id}:${l.mode}:${JSON.stringify(l.geofences)}`} location={l} modes={modes} />
            ))}
            <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 p-4">
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-bold text-slate-700">Masa simpan foto absen</p>
                    <p className="text-[11px] text-slate-400">Setelah lewat, foto dihapus otomatis tiap malam; jam dan lokasi absen tetap tersimpan (UU PDP).</p>
                </div>
                <input type="number" min={7} max={365} value={days} onChange={(e) => setDays(Number(e.target.value))} className={`${smallInput} w-24`} />
                <span className="text-xs text-slate-500">hari</span>
                {days !== retention && (
                    <button
                        onClick={() => router.put(`${BASE}/mobile-attendance-retention`, { photo_retention_days: days }, { preserveScroll: true })}
                        className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1.5 text-xs font-bold text-white hover:bg-brand-700"
                    >
                        <Check size={13} /> Simpan
                    </button>
                )}
            </div>
        </div>
    );
}

function LocationCard({ location, modes }: { location: MobileLocation; modes: Record<string, string> }) {
    const [mode, setMode] = useState(location.mode);
    const [points, setPoints] = useState(location.geofences);
    const [busy, setBusy] = useState(false);
    const [locating, setLocating] = useState<number | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const dirty = mode !== location.mode || JSON.stringify(points) !== JSON.stringify(location.geofences);

    const set = (i: number, patch: Partial<MobileLocation['geofences'][number]>) => setPoints((ps) => ps.map((p, j) => (j === i ? { ...p, ...patch } : p)));
    const here = (i: number) => {
        setLocating(i);
        navigator.geolocation?.getCurrentPosition(
            (p) => {
                set(i, { latitude: p.coords.latitude.toFixed(7), longitude: p.coords.longitude.toFixed(7) });
                setLocating(null);
            },
            () => {
                alert('Lokasi tidak didapat. Izinkan lokasi di browser, atau isi koordinat dari Google Maps.');
                setLocating(null);
            },
            { enableHighAccuracy: true, timeout: 20000 },
        );
    };
    const save = () =>
        router.put(
            `${BASE}/mobile-attendance/${location.id}`,
            {
                mode,
                // An empty coordinate stays empty, so the server asks for it instead of taking 0.
                geofences: points.map((p) => ({ name: p.name, latitude: num(p.latitude), longitude: num(p.longitude), radius_m: num(p.radius_m) })),
            },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: setErrors,
                onSuccess: () => setErrors({}),
            },
        );

    return (
        <div className="rounded-2xl border border-slate-200 p-4">
            <div className="flex flex-wrap items-center gap-3">
                <p className="min-w-40 flex-1 text-sm font-bold text-slate-700">{location.name}</p>
                <select value={mode} onChange={(e) => setMode(e.target.value as MobileLocation['mode'])} className={`${smallInput} w-80`}>
                    {Object.entries(modes).map(([v, l]) => (
                        <option key={v} value={v}>
                            {l}
                        </option>
                    ))}
                </select>
                {dirty && (
                    <button onClick={save} className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1.5 text-xs font-bold text-white hover:bg-brand-700">
                        {busy ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />} Simpan
                    </button>
                )}
            </div>
            {mode !== 'off' && (
                <div className="mt-3 space-y-2">
                    {points.map((p, i) => (
                        <div key={i} className="flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 p-2">
                            <MapPin size={15} className="text-brand-600" />
                            <input value={p.name} onChange={(e) => set(i, { name: e.target.value })} placeholder="Nama titik, mis. Pit 3" className={`${smallInput} w-40`} />
                            <input value={p.latitude} onChange={(e) => set(i, { latitude: e.target.value })} placeholder="Latitude" inputMode="decimal" className={`${smallInput} w-32 font-mono`} />
                            <input value={p.longitude} onChange={(e) => set(i, { longitude: e.target.value })} placeholder="Longitude" inputMode="decimal" className={`${smallInput} w-32 font-mono`} />
                            <label className="flex items-center gap-1 text-xs text-slate-500">
                                Radius
                                <input type="number" min={20} max={20000} value={p.radius_m} onChange={(e) => set(i, { radius_m: e.target.value })} className={`${smallInput} w-20`} />m
                            </label>
                            <button onClick={() => here(i)} title="Pakai lokasi saya sekarang" className="rounded-lg p-1.5 text-slate-500 hover:bg-white hover:text-brand-700">
                                {locating === i ? <Loader2 size={15} className="animate-spin" /> : <LocateFixed size={15} />}
                            </button>
                            {p.latitude !== '' && p.longitude !== '' && (
                                <a href={`https://www.google.com/maps?q=${p.latitude},${p.longitude}`} target="_blank" rel="noreferrer" className="text-xs font-bold text-brand-700 hover:underline">
                                    cek peta
                                </a>
                            )}
                            <button onClick={() => setPoints((ps) => ps.filter((_, j) => j !== i))} title="Hapus titik" className="ml-auto rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600">
                                <Trash2 size={15} />
                            </button>
                        </div>
                    ))}
                    <button
                        onClick={() => setPoints((ps) => [...ps, { name: ps.length ? `Titik ${ps.length + 1}` : 'Kantor', latitude: '', longitude: '', radius_m: 200 }])}
                        className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-bold text-brand-700 hover:bg-brand-50"
                    >
                        <Plus size={13} /> Tambah titik area
                    </button>
                    {Object.values(errors).length > 0 && <p className="text-xs text-rose-600">{Object.values(errors)[0]}</p>}
                    {mode === 'flag' && points.length === 0 && <p className="text-[11px] text-amber-700">Tanpa titik area, semua absen HP di lokasi ini akan ditandai "di luar lokasi".</p>}
                </div>
            )}
        </div>
    );
}
