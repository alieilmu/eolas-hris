import type { CSSProperties } from 'react';

/**
 * The brand / accent scales drawn from two colours, as the server does for every page
 * (App\Support\Branding\Palette): the chosen colour is brand-600 / accent-400, the lighter
 * steps are mixed with white and the darker ones with black.
 */
const BRAND: [number, number][] = [
    [50, 0.93],
    [100, 0.85],
    [200, 0.7],
    [300, 0.5],
    [400, 0.28],
    [500, 0.13],
    [600, 0],
    [700, -0.18],
    [800, -0.34],
    [900, -0.5],
    [950, -0.66],
];
const ACCENT: [number, number][] = [
    [50, 0.9],
    [100, 0.75],
    [200, 0.5],
    [300, 0.3],
    [400, 0],
    [500, -0.09],
    [600, -0.23],
    [700, -0.43],
    [800, -0.57],
];

export const isHex = (v: string) => /^#[0-9a-fA-F]{6}$/.test(v);

function mix(hex: string, share: number): string {
    const target = share >= 0 ? 255 : 0;
    const s = Math.abs(share);
    return (
        '#' +
        [1, 3, 5]
            .map((i) => parseInt(hex.slice(i, i + 2), 16))
            .map((c) => Math.round(c + (target - c) * s))
            .map((c) => c.toString(16).padStart(2, '0'))
            .join('')
    );
}

/** CSS variables for a wrapper element, so Tailwind's brand-* / accent-* classes inside it use these colours. */
export function paletteVars(brand: string, accent: string): CSSProperties {
    const vars: Record<string, string> = {};
    if (isHex(brand)) for (const [step, share] of BRAND) vars[`--color-brand-${step}`] = mix(brand.toLowerCase(), share);
    if (isHex(accent)) for (const [step, share] of ACCENT) vars[`--color-accent-${step}`] = mix(accent.toLowerCase(), share);
    return vars as CSSProperties;
}
