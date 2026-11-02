import type { SVGAttributes } from 'react';

/**
 * Lumberjack's mark, the rings of a log with a crack in them, as on the favicon.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <g
                fill="none"
                stroke="var(--lj-amber)"
                strokeWidth={1.6}
                strokeLinecap="round"
            >
                <circle cx="12" cy="12" r="10" />
                <circle cx="12.5" cy="12.5" r="6.5" />
                <circle cx="13" cy="13" r="3" />
                <path d="M12 2l1 5" />
            </g>
        </svg>
    );
}
