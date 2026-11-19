import { Form, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { TreeOption } from '@/components/lj/tree-select';
import { referenceLabel } from '@/components/lj/activity-filters';
import type { Reference } from '@/components/lj/activity-filters';
import { HostChart } from '@/components/lj/host-charts';
import {
    Button,
    Card,
    CardHeader,
    Field,
    Input,
    StatCard,
} from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import {
    formatCompact,
    formatMoney,
    formatNumber,
    formatPercent,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { lookup as keyLookup } from '@/routes/ai/keys';
import { search as targetsSearch } from '@/routes/ai/targets';
import type { Period, TeamShared } from '@/types/lumberjack';

export type AiTotals = {
    calls: number;
    errors: number;
    tokens: number;
    input_tokens: number;
    output_tokens: number;
    cache_read_tokens: number;
    cache_write_tokens: number;
    reasoning_tokens: number;
    cost_ours: number;
    cost_customer: number;
    cost: number;
    unpriced_tokens: number;
};

export type AiSummary = AiTotals & { previous: AiTotals };

type ChartSeries = {
    name: string;
    points: { t: string; v: number }[];
}[];

export type AiChart = { cost: ChartSeries; tokens: ChartSeries };

export type AiKeyRow = {
    uuid: string;
    short_id: string;
    /** Given in Lumberjack; shown before the label the app reports. */
    name: string | null;
    label: string | null;
    provider: string | null;
    owner: Reference | null;
    payer: 'us' | 'customer' | null;
};

/** The periods the AI pages offer: the totals are hourly, so an hour says little. */
export const AI_PERIODS: Period[] = ['24h', '7d', '30d'];

/** An amount in the team's currency. */
export function useMoney(): (value: number) => string {
    const { team } = usePage<{ team: TeamShared }>().props;

    return (value) => formatMoney(value, team.currency);
}

/** What a key is shown as: its name, else its label, else its short id. */
export function keyName(key: AiKeyRow): string {
    return key.name ?? key.label ?? key.short_id;
}

/**
 * A key as a TreeSelect option, filed under its provider (as recorded: the UI names no
 * providers), and found by its name, label, short id and owner too.
 */
export function keyOption(key: AiKeyRow): TreeOption {
    return {
        value: key.uuid,
        label: `${keyName(key)} (${key.short_id})${key.owner ? ` · ${referenceLabel(key.owner)}` : ''}`,
        path: key.provider ? [key.provider] : undefined,
        keywords: [
            key.name,
            key.label,
            key.short_id,
            key.owner ? referenceLabel(key.owner) : null,
            key.provider,
        ]
            .filter(Boolean)
            .join(' '),
    };
}

/** A key owner or actor as one TreeSelect value: JSON, since a type may hold a colon. */
export function encodeReference(reference: Reference): string {
    return JSON.stringify([reference.type, reference.id]);
}

export function decodeReference(value: string): Reference {
    const [type, id] = JSON.parse(value) as [string, string];

    return { type, id };
}

/** A key owner or actor as a TreeSelect option, filed under its type. */
export function referenceOption(reference: Reference): TreeOption {
    return {
        value: encodeReference(reference),
        label: reference.id,
        path: [reference.type],
        keywords: `${reference.type}:${reference.id}`,
    };
}

const TARGET_SEARCH_DELAY_MS = 200;

/**
 * The keys, key owners or actors the server finds for what is typed in a budget or price
 * form, asked a moment after the last key; an older answer never overwrites a newer one.
 * Every option seen is remembered, so a picked one still shows once a search leaves it out.
 * The first answer is asked for right away, so the groups are there to unfold when opened.
 * `noMatches` says what an empty list means: still searching, a failed search, or nothing.
 */
export function useAiTargetSearch(
    project: string,
    kind: 'keys' | 'owners' | 'actors',
): {
    options: TreeOption[];
    onSearch: (query: string) => void;
    known: (value: string) => TreeOption | undefined;
    noMatches: string;
    busy: boolean;
} {
    const t = useT();
    const [options, setOptions] = useState<TreeOption[]>([]);
    // Wrapped, so asking the same text again after a failure asks anew.
    const [query, setQuery] = useState({ q: '' });
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    // null after a failure: whatever is asked next is asked.
    const asked = useRef<string | null>('');
    const latest = useRef(0);
    const seen = useRef(new Map<string, TreeOption>());

    useEffect(() => {
        const controller = new AbortController();
        const ticket = ++latest.current;
        const current = () =>
            ticket === latest.current && !controller.signal.aborted;
        const timer = window.setTimeout(() => {
            fetch(targetsSearch(project, { query: { kind, q: query.q } }).url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then(async (response) => {
                    // Throttled (429) or refused (422): said so, not "nothing matches".
                    const rows: (AiKeyRow | Reference)[] = response.ok
                        ? await response.json()
                        : [];

                    if (!current()) {
                        return;
                    }

                    const found = rows.map((row) =>
                        kind === 'keys'
                            ? keyOption(row as AiKeyRow)
                            : referenceOption(row as Reference),
                    );
                    found.forEach((option) =>
                        seen.current.set(option.value, option),
                    );
                    setOptions(found);
                    setFailed(!response.ok);

                    if (!response.ok) {
                        asked.current = null;
                    }
                })
                .catch(() => {
                    if (current()) {
                        setFailed(true);
                        asked.current = null;
                    }
                })
                .finally(() => current() && setLoading(false));
        }, TARGET_SEARCH_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [project, kind, query]);

    return {
        options,
        onSearch: useCallback((q: string) => {
            if (q === asked.current) {
                return;
            }

            asked.current = q;
            setQuery({ q });
            setLoading(true);
        }, []),
        known: useCallback((value: string) => seen.current.get(value), []),
        busy: loading,
        noMatches: loading
            ? t('ai.keys.searching')
            : failed
              ? t('ai.keys.search_failed')
              : t('ai.keys.no_matches'),
    };
}

export function ownerLabel(owner: Reference | null, none: string): string {
    return owner ? referenceLabel(owner) : none;
}

/**
 * "12 % more", against the period before; "New" when nothing came before.
 */
export function useChange(): (current: number, previous: number) => string {
    const t = useT();

    return (current, previous) => {
        if (previous === 0) {
            return current === 0 ? '' : t('ai.change.new');
        }

        const change = (current - previous) / previous;

        if (Math.abs(change) < 0.005) {
            return t('ai.change.same');
        }

        return t(change > 0 ? 'ai.change.up' : 'ai.change.down', {
            percent: formatPercent(Math.abs(change), 0),
        });
    };
}

/**
 * Tokens, both costs, calls and the error rate of the period, each against the period
 * before, and a warning when some tokens have no price.
 */
export function AiSummaryTiles({
    summary,
    pricesHref,
}: {
    summary: AiSummary | undefined;
    pricesHref: string;
}) {
    const t = useT();
    const money = useMoney();
    const change = useChange();

    if (summary === undefined) {
        return (
            <div
                className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                aria-busy="true"
            >
                {[0, 1, 2, 3, 4].map((i) => (
                    <Skeleton key={i} className="h-[92px] bg-panel-2" />
                ))}
            </div>
        );
    }

    const rate = summary.calls > 0 ? summary.errors / summary.calls : 0;

    return (
        <div className="flex flex-col gap-3">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <StatCard
                    label={t('ai.kpis.tokens')}
                    value={formatCompact(summary.tokens)}
                    note={[
                        t('ai.kpis.tokens_note', {
                            input: formatCompact(summary.input_tokens),
                            output: formatCompact(summary.output_tokens),
                        }),
                        change(summary.tokens, summary.previous.tokens),
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />
                <StatCard
                    label={t('ai.kpis.cost_ours')}
                    value={money(summary.cost_ours)}
                    note={change(summary.cost_ours, summary.previous.cost_ours)}
                />
                <StatCard
                    label={t('ai.kpis.cost_customer')}
                    value={money(summary.cost_customer)}
                    note={
                        change(
                            summary.cost_customer,
                            summary.previous.cost_customer,
                        ) || t('ai.kpis.cost_customer_note')
                    }
                />
                <StatCard
                    label={t('ai.kpis.calls')}
                    value={formatNumber(summary.calls)}
                    note={change(summary.calls, summary.previous.calls)}
                />
                <StatCard
                    label={t('ai.kpis.error_rate')}
                    value={formatPercent(rate, 1)}
                    tone={rate > 0.05 ? 'err' : rate > 0 ? 'warn' : undefined}
                    note={t(
                        'ai.kpis.error_rate_note',
                        { count: formatNumber(summary.errors) },
                        summary.errors,
                    )}
                />
            </div>
            {summary.unpriced_tokens > 0 && (
                <p
                    role="status"
                    className="flex flex-wrap items-center gap-2 rounded-lg border border-warn-line bg-warn-bg px-4 py-2.5 text-[13px] text-warn"
                >
                    {t('ai.kpis.unpriced', {
                        tokens: formatNumber(summary.unpriced_tokens),
                    })}
                    <Link href={pricesHref} className="underline">
                        {t('ai.kpis.unpriced_link')}
                    </Link>
                </p>
            )}
        </div>
    );
}

// ponytail: five colors repeat from the sixth line on; the server folds the rest into "Other".
const COLORS = [
    'var(--lj-amber)',
    'var(--lj-info)',
    'var(--lj-ok)',
    'var(--lj-err)',
    'var(--lj-ink-3)',
    'var(--lj-line-strong)',
];

/**
 * Cost and tokens over the period, one line per model.
 */
export function AiCharts({
    chart,
    period,
}: {
    chart: AiChart | undefined;
    period: Period;
}) {
    const t = useT();
    const money = useMoney();
    const names = chart?.cost.map((s) => s.name) ?? [];
    const colorOf = (name: string) =>
        COLORS[Math.max(0, names.indexOf(name)) % COLORS.length];

    return (
        <Card aria-labelledby="ai-charts">
            <CardHeader
                id="ai-charts"
                title={t('ai.chart.title')}
                aside={
                    chart && (
                        <ul className="flex flex-wrap gap-3 text-xs text-ink-2">
                            {names.map((name) => (
                                <li
                                    key={name}
                                    className="flex items-center gap-1.5"
                                >
                                    <span
                                        aria-hidden="true"
                                        className="h-2.5 w-3 rounded-sm"
                                        style={{ background: colorOf(name) }}
                                    />
                                    <span className="font-mono">{name}</span>
                                </li>
                            ))}
                        </ul>
                    )
                }
            />
            {chart === undefined ? (
                <div className="grid gap-4 p-4 lg:grid-cols-2" aria-busy="true">
                    <Skeleton className="h-40 bg-panel-2" />
                    <Skeleton className="h-40 bg-panel-2" />
                </div>
            ) : (
                <div className="grid gap-4 p-4 lg:grid-cols-2">
                    <HostChart
                        title={t('ai.chart.cost')}
                        series={chart.cost}
                        period={period}
                        colorOf={colorOf}
                        format={money}
                    />
                    <HostChart
                        title={t('ai.chart.tokens')}
                        series={chart.tokens}
                        period={period}
                        colorOf={colorOf}
                        format={formatCompact}
                        whole
                    />
                </div>
            )}
        </Card>
    );
}

/** Table rows standing in for a deferred table. */
export function TableSkeleton({ rows = 5 }: { rows?: number }) {
    return (
        <div className="flex flex-col gap-2 p-4" aria-busy="true">
            {Array.from({ length: rows }, (_, i) => (
                <Skeleton key={i} className="h-5 w-full bg-panel-2" />
            ))}
        </div>
    );
}

async function sha256Hex(text: string): Promise<string> {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(text),
    );

    return Array.from(new Uint8Array(digest), (b) =>
        b.toString(16).padStart(2, '0'),
    ).join('');
}

/**
 * Finds a key by the key itself: the browser hashes it once per driver the app could have
 * reported (the way the library does), and only those hashes are sent, so nobody has to
 * know the driver. The key field has no name, so it is never part of the form's data.
 */
export function KeyLookupButton({
    project,
    drivers,
}: {
    project: string;
    /** The project's keys' drivers first, then the built-in ones. */
    drivers: string[];
}) {
    const t = useT();
    const [open, setOpen] = useState(false);
    // Forgets the key and its hashes, so neither is there the next time the dialog opens.
    const forget = () => {
        setKey('');
        setHashed({ key: '', hashes: [] });
    };
    const toggle = (next: boolean) => {
        setOpen(next);

        if (!next) {
            forget();
        }
    };
    // Masked by CSS where the browser can, so password managers do not offer to save it;
    // a password field (that asks them not to) where it cannot.
    const masked =
        typeof CSS !== 'undefined' &&
        CSS.supports('-webkit-text-security', 'disc');
    const [key, setKey] = useState('');
    // Tied to the key they were made for, so a key typed since sends nothing stale.
    const [hashed, setHashed] = useState<{ key: string; hashes: string[] }>({
        key: '',
        hashes: [],
    });
    const hashes = hashed.key === key.trim() ? hashed.hashes : [];
    // WebCrypto exists only on a secure origin (HTTPS or localhost).
    const canHash = typeof crypto !== 'undefined' && !!crypto.subtle;

    useEffect(() => {
        let current = true;

        const trimmed = key.trim();

        if (!canHash || trimmed === '') {
            return;
        }

        void Promise.all(
            drivers.map((driver) => sha256Hex(`${driver}:${trimmed}`)),
        ).then((hex) => current && setHashed({ key: trimmed, hashes: hex }));

        return () => {
            current = false;
        };
    }, [canHash, key, drivers]);

    return (
        <>
            <Button onClick={() => setOpen(true)}>{t('ai.lookup.open')}</Button>
            <Dialog open={open} onOpenChange={toggle}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('ai.lookup.title')}</DialogTitle>
                        <DialogDescription>
                            {t('ai.lookup.description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...keyLookup.form(project)}
                        options={{ preserveScroll: true }}
                        onSuccess={forget}
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                {/* The only fields sent: the key input has no name. */}
                                {hashes.map((hash) => (
                                    <input
                                        key={hash}
                                        type="hidden"
                                        name="hashes[]"
                                        value={hash}
                                    />
                                ))}
                                <Field
                                    label={t('ai.lookup.key')}
                                    htmlFor="ai-lookup-key"
                                    error={
                                        canHash
                                            ? (errors.hashes ??
                                              errors['hashes.0'])
                                            : t('ai.lookup.no_crypto')
                                    }
                                >
                                    <Input
                                        id="ai-lookup-key"
                                        type={masked ? 'text' : 'password'}
                                        value={key}
                                        onChange={(e) => setKey(e.target.value)}
                                        required
                                        autoComplete={
                                            masked ? 'off' : 'new-password'
                                        }
                                        autoCorrect="off"
                                        autoCapitalize="off"
                                        spellCheck={false}
                                        data-1p-ignore
                                        data-lpignore="true"
                                        data-bwignore
                                        data-form-type="other"
                                        className="font-mono [-webkit-text-security:disc]"
                                    />
                                </Field>
                                <DialogFooter>
                                    <Button onClick={() => toggle(false)}>
                                        {t('common.cancel')}
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        disabled={
                                            processing || hashes.length === 0
                                        }
                                    >
                                        {t('ai.lookup.submit')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

export type AiPriceRow = {
    uuid: string;
    scope: 'project' | 'owner' | 'key';
    scope_label: string;
    team_wide: boolean;
    owner: Reference | null;
    key: AiKeyRow | null;
    provider: string | null;
    model_pattern: string;
    valid_from: string;
    input: number;
    output: number;
    cache_read: number | null;
    cache_write: number | null;
    reasoning: number | null;
};

/** What a price applies to: "Team", "Project", "Key owner tenant #7", "Key prod". */
export function usePriceScope(): (price: AiPriceRow) => string {
    const t = useT();

    return (price) =>
        price.scope === 'project'
            ? price.team_wide
                ? t('ai.prices.team_wide')
                : t('ai.prices.this_project')
            : price.scope === 'owner'
              ? `${price.scope_label} ${ownerLabel(price.owner, '')}`
              : `${price.scope_label} ${price.key ? keyName(price.key) : ''}`;
}

/** "In $3.00 · out $15.00 · cache read $0.30", per million tokens. */
export function usePriceText(): (price: AiPriceRow) => string {
    const t = useT();
    const money = useMoney();

    return (price) =>
        [
            ['input', price.input],
            ['output', price.output],
            ['cache_read', price.cache_read],
            ['cache_write', price.cache_write],
            ['reasoning', price.reasoning],
        ]
            .filter(([, value]) => value !== null)
            .map(
                ([field, value]) =>
                    `${t(`ai.prices.form.${field}`)} ${money(value as number)}`,
            )
            .join(' · ');
}
