import { useCallback, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Camera, CheckCircle2, Loader2, LocateFixed, MapPin, RefreshCw, ShieldCheck, TriangleAlert, X } from 'lucide-react';

export interface Geofence {
    name: string;
    latitude: number;
    longitude: number;
    radius_m: number;
}

export interface MobileClockState {
    enabled: boolean;
    mode: 'off' | 'reject' | 'flag';
    location: string | null;
    geofences: Geofence[];
    consented: boolean;
    next: 'in' | 'out';
    today: { time: string; source: string; type: 'in' | 'out' | null; outside: boolean }[];
}

interface Position {
    latitude: number;
    longitude: number;
    accuracy: number;
}

/** Metres between two points (haversine), as the server counts them. */
function distance(a: { latitude: number; longitude: number }, b: { latitude: number; longitude: number }): number {
    const rad = (d: number) => (d * Math.PI) / 180;
    const dLat = rad(b.latitude - a.latitude);
    const dLng = rad(b.longitude - a.longitude);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLng / 2) ** 2;
    return 2 * 6371000 * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** The nearest area and whether the position is inside it (the server decides again on sending). */
function nearest(pos: Position, fences: Geofence[]): { fence: Geofence; meters: number; inside: boolean } | null {
    let best: { fence: Geofence; meters: number; over: number } | null = null;
    for (const fence of fences) {
        const meters = distance(pos, fence);
        if (!best || meters - fence.radius_m < best.over) best = { fence, meters, over: meters - fence.radius_m };
    }
    return best ? { fence: best.fence, meters: Math.round(best.meters), inside: best.over <= 0 } : null;
}

/**
 * Absen dari HP: agree once to the location and selfie being kept (UU PDP), then the GPS
 * position and a photo from the front camera, sent together; the server keeps its own time.
 */
export function MobileClockSheet({
    state,
    consent,
    type,
    onClose,
}: {
    state: MobileClockState;
    consent: { version: string; points: string[] } | null;
    type: 'in' | 'out';
    onClose: () => void;
}) {
    const [agreed, setAgreed] = useState(state.consented);
    const [pos, setPos] = useState<Position | null>(null);
    const [gpsError, setGpsError] = useState<string | null>(null);
    const [locating, setLocating] = useState(false);
    const [photo, setPhoto] = useState<{ file: File; url: string } | null>(null);
    const [cameraError, setCameraError] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const video = useRef<HTMLVideoElement>(null);
    const stream = useRef<MediaStream | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const locate = useCallback(() => {
        if (!navigator.geolocation) {
            setGpsError('HP / browser ini tidak memberi lokasi GPS.');
            return;
        }
        setLocating(true);
        setGpsError(null);
        navigator.geolocation.getCurrentPosition(
            (p) => {
                setPos({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy });
                setLocating(false);
            },
            (e) => {
                setGpsError(e.code === e.PERMISSION_DENIED ? 'Izin lokasi ditolak. Izinkan lokasi untuk aplikasi ini di pengaturan HP, lalu coba lagi.' : 'Lokasi belum didapat. Pastikan GPS menyala, sebaiknya di tempat terbuka.');
                setLocating(false);
            },
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
        );
    }, []);

    const stopCamera = () => {
        stream.current?.getTracks().forEach((t) => t.stop());
        stream.current = null;
    };

    const startCamera = useCallback(async () => {
        setCameraError(null);
        if (!navigator.mediaDevices?.getUserMedia) {
            setCameraError('fallback');
            return;
        }
        try {
            stopCamera();
            stream.current = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 960 } }, audio: false });
            if (video.current) {
                video.current.srcObject = stream.current;
                await video.current.play().catch(() => undefined);
            }
        } catch {
            setCameraError('Kamera tidak bisa dibuka. Izinkan kamera untuk aplikasi ini, atau ambil foto dengan tombol di bawah.');
        }
    }, []);

    useEffect(() => {
        if (agreed) locate();
    }, [agreed, locate]);

    // The live camera while there is no photo yet (again after "Ulangi foto").
    useEffect(() => {
        if (!agreed || photo) return;
        void startCamera();
        return stopCamera;
    }, [agreed, photo, startCamera]);

    useEffect(
        () => () => {
            if (photo) URL.revokeObjectURL(photo.url);
        },
        [photo],
    );

    /** A frame from the live camera, at most 720 px wide, as JPEG. */
    const snap = () => {
        const v = video.current;
        if (!v || !v.videoWidth) return;
        const scale = Math.min(1, 720 / v.videoWidth);
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(v.videoWidth * scale);
        canvas.height = Math.round(v.videoHeight * scale);
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        // As the person saw it (mirrored preview).
        ctx.translate(canvas.width, 0);
        ctx.scale(-1, 1);
        ctx.drawImage(v, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(
            (blob) => {
                if (!blob) return;
                setPhoto({ file: new File([blob], 'selfie.jpg', { type: 'image/jpeg' }), url: URL.createObjectURL(blob) });
                stopCamera();
            },
            'image/jpeg',
            0.82,
        );
    };

    const retake = () => setPhoto(null);

    const submit = () => {
        if (!pos || !photo) return;
        setSending(true);
        setError(null);
        router.post(
            '/self-service/clock',
            { type, latitude: pos.latitude, longitude: pos.longitude, accuracy: Math.round(pos.accuracy), photo: photo.file },
            {
                forceFormData: true,
                onSuccess: () => onClose(),
                onError: (errors) => setError(Object.values(errors)[0] ?? 'Absen gagal dikirim.'),
                onFinish: () => setSending(false),
            },
        );
    };

    const agree = () => router.post('/self-service/clock/consent', {}, { preserveScroll: true, preserveState: true, onSuccess: () => setAgreed(true) });

    const where = pos ? nearest(pos, state.geofences) : null;
    const title = type === 'in' ? 'Absen Masuk' : 'Absen Pulang';

    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-white pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] sm:items-center sm:justify-center sm:bg-slate-900/40 sm:p-6">
            <div className="flex min-h-0 w-full flex-1 flex-col sm:max-w-md sm:flex-none sm:rounded-3xl sm:bg-white sm:shadow-xl">
                <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-3">
                    <h2 className="flex-1 text-base font-black text-slate-800">{title}</h2>
                    <button onClick={onClose} aria-label="Tutup" className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                        <X size={20} />
                    </button>
                </div>

                {!agreed && consent ? (
                    <div className="min-h-0 flex-1 overflow-y-auto p-4">
                        <p className="flex items-center gap-2 text-sm font-bold text-slate-700">
                            <ShieldCheck size={18} className="text-brand-600" /> Persetujuan lokasi & foto
                        </p>
                        <ul className="mt-3 space-y-2.5 text-sm text-slate-600">
                            {consent.points.map((p) => (
                                <li key={p} className="flex gap-2">
                                    <CheckCircle2 size={16} className="mt-0.5 shrink-0 text-brand-500" />
                                    <span>{p}</span>
                                </li>
                            ))}
                        </ul>
                        <button onClick={agree} className="mt-5 w-full rounded-2xl bg-brand-600 px-4 py-3 text-sm font-bold text-white hover:bg-brand-700">
                            Saya setuju, lanjut absen
                        </button>
                        <p className="mt-2 text-center text-[11px] text-slate-400">Tanpa persetujuan, absen tetap bisa lewat mesin absen.</p>
                    </div>
                ) : (
                    <div className="min-h-0 flex-1 overflow-y-auto p-4">
                        {/* The selfie. */}
                        <div className="relative mx-auto aspect-[3/4] w-full max-w-xs overflow-hidden rounded-3xl bg-slate-900">
                            {photo ? (
                                <img src={photo.url} alt="Foto absen" className="h-full w-full object-cover" />
                            ) : cameraError ? (
                                <div className="flex h-full flex-col items-center justify-center gap-3 p-6 text-center text-xs text-slate-300">
                                    <Camera size={28} />
                                    {cameraError !== 'fallback' && <p>{cameraError}</p>}
                                    <button onClick={() => fileInput.current?.click()} className="rounded-xl bg-white px-4 py-2 text-sm font-bold text-slate-800">
                                        Ambil foto
                                    </button>
                                </div>
                            ) : (
                                <video ref={video} playsInline muted className="h-full w-full -scale-x-100 object-cover" />
                            )}
                            <input
                                ref={fileInput}
                                type="file"
                                accept="image/*"
                                capture="user"
                                className="hidden"
                                onChange={(e) => {
                                    const f = e.target.files?.[0];
                                    if (f) setPhoto({ file: f, url: URL.createObjectURL(f) });
                                }}
                            />
                        </div>
                        <div className="mt-3 flex justify-center">
                            {photo ? (
                                <button onClick={retake} className="flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-bold text-slate-500 hover:bg-slate-100">
                                    <RefreshCw size={14} /> Ulangi foto
                                </button>
                            ) : (
                                !cameraError && (
                                    <button
                                        onClick={snap}
                                        aria-label="Ambil foto"
                                        className="flex h-16 w-16 items-center justify-center rounded-full border-4 border-brand-200 bg-brand-600 text-white shadow-lg active:scale-95"
                                    >
                                        <Camera size={24} />
                                    </button>
                                )
                            )}
                        </div>

                        {/* Where. */}
                        <div className="mt-4 rounded-2xl border border-slate-200 p-3 text-sm">
                            <div className="flex items-center gap-2">
                                <MapPin size={16} className="shrink-0 text-brand-600" />
                                <p className="min-w-0 flex-1 font-bold text-slate-700">
                                    {locating ? 'Mencari lokasi…' : pos ? (where ? (where.inside ? `Di dalam area ${where.fence.name}` : `${where.meters.toLocaleString('id-ID')} m dari ${where.fence.name}`) : 'Lokasi didapat') : 'Lokasi belum didapat'}
                                </p>
                                <button onClick={locate} disabled={locating} aria-label="Cari ulang lokasi" className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                                    {locating ? <Loader2 size={16} className="animate-spin" /> : <LocateFixed size={16} />}
                                </button>
                            </div>
                            {pos && <p className="mt-1 pl-6 text-[11px] text-slate-400">Akurasi GPS ±{Math.round(pos.accuracy).toLocaleString('id-ID')} m</p>}
                            {gpsError && <p className="mt-1 pl-6 text-xs text-rose-600">{gpsError}</p>}
                            {pos && where && !where.inside && (
                                <p className="mt-2 flex items-start gap-1.5 rounded-xl bg-amber-50 px-2.5 py-2 text-xs text-amber-800">
                                    <TriangleAlert size={14} className="mt-0.5 shrink-0" />
                                    {state.mode === 'reject'
                                        ? 'Anda di luar area absen. Absen hanya bisa di dalam area kerja.'
                                        : 'Anda di luar area kerja. Absen tetap dicatat, dengan tanda "di luar lokasi" untuk HR.'}
                                </p>
                            )}
                        </div>

                        {error && <p className="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>}
                    </div>
                )}

                {agreed && (
                    <div className="border-t border-slate-100 p-4">
                        <button
                            onClick={submit}
                            disabled={!pos || !photo || sending}
                            className="flex w-full items-center justify-center gap-2 rounded-2xl bg-brand-600 px-4 py-3.5 text-base font-black text-white shadow-lg shadow-brand-200 disabled:opacity-40"
                        >
                            {sending && <Loader2 size={18} className="animate-spin" />} Kirim {title}
                        </button>
                        <p className="mt-1.5 text-center text-[11px] text-slate-400">Jam absen memakai jam server, bukan jam HP.</p>
                    </div>
                )}
            </div>
        </div>
    );
}
