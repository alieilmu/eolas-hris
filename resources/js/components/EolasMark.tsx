/** The Eolas wordmark (public/images), with "HRIS" beside it; light = on a dark background. */
export function EolasMark({ light = false, size = 'md' }: { light?: boolean; size?: 'md' | 'lg' }) {
    return (
        <span className="flex items-center gap-2">
            <img
                src={light ? '/images/eolas-logo-full-white.png' : '/images/eolas-logo-full.png'}
                alt="Eolas"
                className={`${size === 'lg' ? 'h-10' : 'h-7'} w-auto`}
            />
            <span className={`mt-1 text-[10px] font-bold tracking-widest uppercase ${light ? 'text-brand-200' : 'text-brand-600'}`}>HRIS</span>
        </span>
    );
}
