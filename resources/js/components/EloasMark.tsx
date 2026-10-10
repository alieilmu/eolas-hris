import { Leaf } from 'lucide-react';

/** The Eloas wordmark: a leaf in the brand green and the name. */
export function EloasMark({ light = false, size = 'md' }: { light?: boolean; size?: 'md' | 'lg' }) {
    const box = size === 'lg' ? 'h-11 w-11' : 'h-9 w-9';
    return (
        <span className="flex items-center gap-2.5">
            <span className={`${box} flex items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-lg shadow-brand-600/30`}>
                <Leaf size={size === 'lg' ? 22 : 18} strokeWidth={2.5} />
            </span>
            <span className={`text-xl font-black tracking-tight ${light ? 'text-white' : 'text-brand-800'}`}>
                eloas<span className="text-brand-400">.</span>
                <span className={`ml-1 text-xs font-bold tracking-widest uppercase ${light ? 'text-brand-200' : 'text-slate-400'}`}>HRIS</span>
            </span>
        </span>
    );
}
