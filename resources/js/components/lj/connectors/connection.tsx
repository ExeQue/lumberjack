import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { Button, Card, CardHeader } from '@/components/lj/ui';
import { formatPercent } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { destroy as connectionDestroy } from '@/routes/data-source-connections';

/**
 * The tab a connector page shows, kept in the URL's fragment so a reload or a shared link
 * opens it again. The first tab is the default and leaves the fragment empty.
 */
export function useHashTab<T extends string>(
    tabs: readonly T[],
): [T, (tab: T) => void] {
    const [tab, setTabState] = useState<T>(() => {
        const hash =
            typeof window === 'undefined' ? '' : window.location.hash.slice(1);

        return (tabs as readonly string[]).includes(hash)
            ? (hash as T)
            : tabs[0];
    });

    const setTab = (next: T) => {
        setTabState(next);
        // Inertia keeps the page in history.state, so only the fragment changes.
        window.history.replaceState(
            window.history.state,
            '',
            next === tabs[0]
                ? window.location.pathname + window.location.search
                : `#${next}`,
        );
    };

    return [tab, setTab];
}

/** A connector page's sections; arrow keys move between them, as a tab list does. */
export function ConnectorTabs<T extends string>({
    id,
    label,
    tabs,
    current,
    onChange,
    title,
}: {
    id: string;
    label: string;
    tabs: readonly T[];
    current: T;
    onChange: (tab: T) => void;
    title: (tab: T) => string;
}) {
    const onKey = (e: React.KeyboardEvent) => {
        const step = { ArrowRight: 1, ArrowLeft: -1 }[e.key];

        if (step === undefined) {
            return;
        }

        const next =
            tabs[(tabs.indexOf(current) + step + tabs.length) % tabs.length];
        onChange(next);
        document.getElementById(`${id}-tab-${next}`)?.focus();
    };

    return (
        <div
            role="tablist"
            aria-label={label}
            className="flex gap-4 overflow-x-auto border-b border-line"
            onKeyDown={onKey}
        >
            {tabs.map((name) => (
                <button
                    key={name}
                    type="button"
                    role="tab"
                    id={`${id}-tab-${name}`}
                    aria-selected={current === name}
                    aria-controls={`${id}-panel-${name}`}
                    tabIndex={current === name ? 0 : -1}
                    onClick={() => onChange(name)}
                    className="h-10 cursor-pointer border-b-2 border-transparent px-1 whitespace-nowrap text-ink-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-amber aria-selected:border-amber aria-selected:text-ink"
                >
                    {title(name)}
                </button>
            ))}
        </div>
    );
}

export function ConnectorPanel({
    id,
    name,
    current,
    children,
}: {
    id: string;
    name: string;
    current: string;
    children: ReactNode;
}) {
    return (
        <div
            role="tabpanel"
            id={`${id}-panel-${name}`}
            aria-labelledby={`${id}-tab-${name}`}
            hidden={current !== name}
            className="flex flex-col gap-4"
        >
            {current === name && children}
        </div>
    );
}

/**
 * The connection a connector page reads: where it reads from, connecting another one through
 * the connector's own dialog, and disconnecting it. What only one connector can change goes
 * in as children.
 */
export function ConnectionCard({
    project,
    connection,
    description,
    connectLabel,
    disconnectQuestion,
    dialog,
    children,
}: {
    project: string;
    connection: string;
    description: string;
    connectLabel: string;
    disconnectQuestion: string;
    dialog: (onClose: () => void) => ReactNode;
    children?: ReactNode;
}) {
    const t = useT();
    const [connecting, setConnecting] = useState(false);

    return (
        <Card aria-labelledby="connector-connection">
            <CardHeader
                id="connector-connection"
                title={t('connectors.connection.title')}
                description={description}
                aside={
                    <span className="inline-flex flex-wrap gap-2">
                        <Button onClick={() => setConnecting(true)}>
                            {connectLabel}
                        </Button>
                        <ConfirmButton
                            question={disconnectQuestion}
                            confirmLabel={t('connectors.connection.disconnect')}
                            onConfirm={() =>
                                router.delete(
                                    connectionDestroy([project, connection])
                                        .url,
                                )
                            }
                        >
                            {t('connectors.connection.disconnect')}
                        </ConfirmButton>
                    </span>
                }
            />
            {children && (
                <div className="flex flex-col gap-4 p-4">{children}</div>
            )}
            {connecting && dialog(() => setConnecting(false))}
        </Card>
    );
}

/** How a figure moved against the period before, or nothing when there was none. */
export function useTrend() {
    const t = useT();

    return (current: number | null, previous: number | null): string => {
        if (current === null || previous === null || previous === 0) {
            return '';
        }

        const change = (current - previous) / previous;

        if (Math.abs(change) < 0.005) {
            return t('overview.trend.same');
        }

        return t(change > 0 ? 'overview.trend.up' : 'overview.trend.down', {
            percent: formatPercent(Math.abs(change), 0),
        });
    };
}
