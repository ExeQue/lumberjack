export function LogMark({ size = 22 }: { size?: number }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="var(--lj-amber)"
            strokeWidth={1.6}
            aria-hidden="true"
        >
            <circle cx="12" cy="12" r="10" />
            <circle cx="12.5" cy="12.5" r="6.5" />
            <circle cx="13" cy="13" r="3" />
            <path d="M12 2l1 5" />
        </svg>
    );
}
