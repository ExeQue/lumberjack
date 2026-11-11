import { Plug } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Mark = {
    viewBox: string;
    /** Drawn in currentColor. */
    shapes: ReactNode;
    /** The brand's own color, where it is not black (which currentColor already is in light mode). */
    color?: string;
};

/** Each connector's mark, by its key in the catalog. Monochrome, single color, as shipped by their sources. */
const MARKS: Record<string, Mark> = {
    // Simple Icons, icons/github.svg (CC0 1.0, https://github.com/simple-icons/simple-icons).
    // GitHub's logo guidelines apply: https://github.com/logos
    github: {
        viewBox: '0 0 24 24',
        shapes: (
            <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
        ),
    },
    // Simple Icons, icons/umami.svg (CC0 1.0, https://github.com/simple-icons/simple-icons), drawn
    // from Umami's own logo (MIT, https://github.com/umami-software/umami/blob/master/assets/logo.svg).
    umami: {
        viewBox: '0 0 24 24',
        shapes: (
            <path d="M2.203 8.611H.857a.845.845 0 0 0-.841.841v.858a13.31 13.31 0 0 0-.016.6c0 6.627 5.373 12 12 12 6.527 0 11.837-5.212 11.996-11.701 0-.025.004-.05.004-.075V9.452a.845.845 0 0 0-.841-.841h-1.346c-1.159-4.329-5.112-7.521-9.805-7.521-4.692 0-8.645 3.192-9.805 7.521Zm18.444 0H3.37c1.127-3.702 4.57-6.399 8.638-6.399 4.069 0 7.512 2.697 8.639 6.399Z" />
        ),
    },
    // mosparo's bird, from its own repository (MIT): assets/images/mosparo-logo-bird.svg at
    // https://github.com/mosparo/mosparo/blob/4f4820aa65123b5116a5967798a0f37d4f1b5f47/assets/images/mosparo-logo-bird.svg
    mosparo: {
        viewBox: '0 0 62.82 50.35',
        shapes: (
            <>
                <path d="M55.45,32.87a26,26,0,0,0-43-25.64L0,5.56,5.75,17.89A26,26,0,0,0,21.5,50.35l1.46-4A21.75,21.75,0,0,1,10,18.5l.3-.83L7.13,10.8l6.86.92.74-.78A21.71,21.71,0,0,1,51.44,31.4L20.07,19.91l-.74,2A18.89,18.89,0,0,0,54,36.85l7.39,2.71,1.46-4ZM32,42.15a14.65,14.65,0,0,1-9.3-16.74L50,35.38A14.67,14.67,0,0,1,32,42.15Z" />
                <circle
                    cx="26.82"
                    cy="12.97"
                    r="3.96"
                    transform="translate(-2.43 18.65) rotate(-36.8)"
                />
            </>
        ),
    },
    // The Vipps MobilePay mark (its smile, without the black tile around it), from the favicon of
    // its developer documentation: https://developer.vippsmobilepay.com/img/logos/favicon.svg
    // No license is given; it is Vipps MobilePay's trademark, shown only to name the service.
    mobilepay: {
        viewBox: '5.87 8 20.26 16',
        shapes: (
            <path d="M25.4948 18.8952C23.1955 21.974 20.2543 23.8213 16.4859 23.8213C12.3799 23.8213 8.85635 21.5437 6.31365 17.6347C5.60952 16.6191 5.79351 15.4499 6.5585 14.895C7.38578 14.3097 8.51877 14.556 9.25478 15.6643C11.0934 18.3737 13.5158 20.2209 16.4859 20.2209C19.456 20.2209 21.1425 18.7735 22.7362 16.6814L22.7376 16.6814V16.6785C23.5939 15.5397 24.7298 15.3239 25.4948 15.9396C26.3221 16.5858 26.3525 17.7565 25.4948 18.8952ZM19.611 12.8014C20.8991 12.8014 22.0016 11.8162 22.0016 10.4007C22.0016 8.98521 20.8991 8 19.611 8C18.323 8 17.2205 8.98521 17.2205 10.4007C17.2205 11.8162 18.323 12.8014 19.611 12.8014Z" />
        ),
    },
};

/**
 * A connector's mark, decorative beside its name. Monochrome in currentColor; `brand` paints it
 * in the brand's color where that is not black, so it stays readable in dark mode.
 */
export function ConnectorIcon({
    connector,
    brand = false,
    className,
}: {
    connector: string;
    brand?: boolean;
    className?: string;
}) {
    const mark = MARKS[connector];

    if (mark === undefined) {
        return <Plug className={className} aria-hidden="true" />;
    }

    return (
        <svg
            viewBox={mark.viewBox}
            fill="currentColor"
            className={cn('shrink-0', className)}
            style={brand && mark.color ? { color: mark.color } : undefined}
            aria-hidden="true"
            focusable="false"
        >
            {mark.shapes}
        </svg>
    );
}
