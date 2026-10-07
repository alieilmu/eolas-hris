import { usePage } from '@inertiajs/react';
import type { Branding } from '@/types';

/** White label settings shared with every page (Settings → White Label). */
export function useBranding(): Branding {
    return usePage().props.branding;
}

/** The uploaded logo, or the first letter of the app name in the brand colour when there is none. */
export function BrandLogo({ branding, className = 'h-8' }: { branding?: Branding; className?: string }) {
    const shared = usePage().props.branding;
    const b = branding ?? shared;
    if (b.logo_url) return <img src={b.logo_url} alt={b.company_name ?? b.app_name} className={`${className} w-auto shrink-0 object-contain`} />;
    return (
        <span
            aria-label={b.app_name}
            className={`${className} flex aspect-square shrink-0 items-center justify-center rounded-xl bg-brand-600 text-sm font-black text-white ring-2 ring-accent-400`}
        >
            {b.app_name.trim().charAt(0).toUpperCase() || 'H'}
        </span>
    );
}

/** "App name · Company name", leaving out what is not set. */
export function brandLine(b: Branding): string {
    return [b.app_name, b.company_name].filter(Boolean).join(' · ');
}

/** Login without a full logo: the logo with the company name and tagline. */
export function LoginMark({ b }: { b: Branding }) {
    return (
        <div className="flex flex-col items-center gap-2 text-center">
            <BrandLogo branding={b} className="h-14" />
            {b.company_name && <p className="text-base leading-tight font-black text-brand-800">{b.company_name}</p>}
            {b.tagline && <p className="-mt-1 text-xs font-semibold text-slate-500">{b.tagline}</p>}
        </div>
    );
}
