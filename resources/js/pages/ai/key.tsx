import { BudgetsUsedCard } from '@/components/lj/ai-budgets';
import type { AiBudgetRow } from '@/components/lj/ai-budgets';
import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { referenceLabel } from '@/components/lj/activity-filters';
import type { Reference } from '@/components/lj/activity-filters';
import {
    AI_PERIODS,
    AiCharts,
    AiSummaryTiles,
    keyName,
    usePriceScope,
    usePriceText,
} from '@/components/lj/ai-usage';
import type {
    AiChart,
    AiKeyRow,
    AiPriceRow,
    AiSummary,
} from '@/components/lj/ai-usage';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    Mono,
    PageTitle,
    Select,
    Switch,
    numClass,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useProjectLive } from '@/lib/use-project-live';
import { index as aiIndex } from '@/routes/ai';
import { show as ownerShow } from '@/routes/ai/owners';
import { show as actorShow } from '@/routes/ai/actors';
import {
    name as keyNameRoute,
    payer as keyPayer,
    show as keyShow,
} from '@/routes/ai/keys';
import { index as pricesIndex } from '@/routes/ai/prices';
import { show as projectShow } from '@/routes/projects';
import { show as traceShow } from '@/routes/traces';
import type {
    DashboardFilters,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Payer = 'us' | 'customer';

type Call = {
    id: number;
    at: string;
    provider: string;
    model: string;
    operation: string;
    status: 'ok' | 'error';
    error: string | null;
    input_tokens: number | null;
    output_tokens: number | null;
    cache_read_tokens: number | null;
    cache_write_tokens: number | null;
    reasoning_tokens: number | null;
    duration_ms: number | null;
    actor: Reference | null;
    trace_id: string | null;
    environment: string | null;
};

type Props = {
    project: ProjectHeader;
    filters: DashboardFilters & { errors: boolean };
    ai_key: AiKeyRow & {
        first_seen_at: string;
        last_seen_at: string;
        effective_payer: Payer;
    };
    default_payer: Payer;
    summary?: AiSummary;
    chart?: AiChart;
    prices: {
        provider: string;
        model: string;
        /** Set for calls naming another owner than the key's. */
        owner: Reference | null;
        price: AiPriceRow | null;
    }[];
    calls: Call[];
    /** Deferred: the budgets that count this key. */
    budgets?: AiBudgetRow[];
    next_url: string | null;
    prev_url: string | null;
    can_update: boolean;
};

const pagerClass =
    'inline-flex h-8 items-center rounded-md border border-line-strong bg-panel-2 px-3 text-ink';

const tokens = (value: number | null) =>
    value === null ? '-' : formatNumber(value);

export default function AiKey({
    project,
    filters,
    ai_key: aiKey,
    default_payer,
    summary,
    chart,
    prices,
    calls,
    budgets,
    next_url,
    prev_url,
    can_update,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const scope = usePriceScope();
    const priceText = usePriceText();
    const name = keyName(aiKey);
    const query = { period: filters.period, environment: filters.environment };
    // What the key pays as when it says nothing itself.
    const inherited: Payer = aiKey.owner ? 'customer' : default_payer;

    useProjectLive(
        project.uuid,
        ['ai.changed'],
        ['summary', 'chart', 'calls', 'next_url', 'prev_url'],
    );

    const showErrors = (errors: boolean) =>
        router.get(
            keyShow([project.uuid, aiKey.uuid], {
                query: { ...query, ...(errors ? { errors: 1 } : {}) },
            }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title={`${t('ai.key.title', { name })} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    {
                        label: t('ai.title'),
                        href: aiIndex(project.uuid, { query }).url,
                    },
                    { label: name },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                    periods={AI_PERIODS}
                />
            </TopBar>
            <PageBody>
                <PageTitle
                    title={
                        <span>
                            {t('ai.key.title', { name })}
                            {aiKey.name && aiKey.label && (
                                <span className="ml-2 text-sm font-normal text-ink-3">
                                    {aiKey.label}
                                </span>
                            )}
                            <span className="ml-2 font-mono text-sm font-normal text-ink-3">
                                {aiKey.short_id}
                            </span>
                        </span>
                    }
                    description={
                        <>
                            {t('ai.key.description', {
                                provider: aiKey.provider ?? '-',
                                first: formatDate(aiKey.first_seen_at),
                                last: formatDateTime(aiKey.last_seen_at),
                            })}
                            {aiKey.owner && (
                                <>
                                    {' '}
                                    <Link
                                        href={
                                            ownerShow(project.uuid, {
                                                query: {
                                                    ...query,
                                                    ...aiKey.owner,
                                                },
                                            }).url
                                        }
                                        className="text-amber hover:underline"
                                    >
                                        {t('ai.key.owner', {
                                            owner: referenceLabel(aiKey.owner),
                                        })}
                                    </Link>
                                </>
                            )}
                        </>
                    }
                />

                <AiSummaryTiles
                    summary={summary}
                    pricesHref={pricesIndex(project.uuid).url}
                />
                <AiCharts chart={chart} period={filters.period} />
                <BudgetsUsedCard project={project.uuid} budgets={budgets} />

                <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <Card aria-labelledby="ai-key-prices">
                        <CardHeader
                            id="ai-key-prices"
                            title={t('ai.key.prices')}
                            description={t('ai.key.prices_description')}
                        />
                        {prices.length === 0 ? (
                            <EmptyState title={t('ai.no_data')} />
                        ) : (
                            <div className="relative overflow-x-auto">
                                <table className={tableClass}>
                                    <thead>
                                        <tr>
                                            <th className={thClass}>
                                                {t('ai.columns.model')}
                                            </th>
                                            <th className={thClass}>
                                                {t('ai.columns.price')}
                                            </th>
                                            <th className={thClass}>
                                                {t('ai.prices.applies_to')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {prices.map((row) => (
                                            <tr
                                                key={`${row.provider}:${row.model}:${row.owner?.type ?? ''}:${row.owner?.id ?? ''}`}
                                            >
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'font-mono text-xs',
                                                    )}
                                                >
                                                    {row.model}
                                                    {row.owner && (
                                                        <span className="ml-2 text-ink-3">
                                                            {t(
                                                                'ai.key.for_owner',
                                                                {
                                                                    owner: referenceLabel(
                                                                        row.owner,
                                                                    ),
                                                                },
                                                            )}
                                                        </span>
                                                    )}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-xs',
                                                    )}
                                                >
                                                    {row.price ? (
                                                        priceText(row.price)
                                                    ) : (
                                                        <Badge tone="warn">
                                                            {t(
                                                                'ai.key.no_price',
                                                            )}
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-xs text-ink-2',
                                                    )}
                                                >
                                                    {row.price && (
                                                        <>
                                                            {scope(row.price)}
                                                            <span className="ml-1 font-mono text-ink-3">
                                                                {
                                                                    row.price
                                                                        .model_pattern
                                                                }
                                                            </span>
                                                        </>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    <div className="flex flex-col gap-4">
                        {can_update && (
                            <Card aria-labelledby="ai-key-name">
                                <CardHeader
                                    id="ai-key-name"
                                    title={t('ai.key.name')}
                                    description={t('ai.key.name_hint')}
                                />
                                <Form
                                    {...keyNameRoute.form([
                                        project.uuid,
                                        aiKey.uuid,
                                    ])}
                                    options={{ preserveScroll: true }}
                                    className="flex flex-wrap items-end gap-3 p-4"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                label={t('ai.key.name')}
                                                htmlFor="ai-key-name-input"
                                                error={errors.name}
                                                className="min-w-0 flex-1"
                                            >
                                                <Input
                                                    id="ai-key-name-input"
                                                    name="name"
                                                    maxLength={64}
                                                    autoComplete="off"
                                                    placeholder={
                                                        aiKey.label ??
                                                        aiKey.short_id
                                                    }
                                                    defaultValue={
                                                        aiKey.name ?? ''
                                                    }
                                                />
                                            </Field>
                                            <Button
                                                type="submit"
                                                variant="primary"
                                                disabled={processing}
                                            >
                                                {t('ai.key.payer_save')}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </Card>
                        )}
                        <Card aria-labelledby="ai-key-payer">
                            <CardHeader
                                id="ai-key-payer"
                                title={t('ai.key.payer')}
                                description={t('ai.key.payer_hint')}
                            />
                            {can_update ? (
                                <Form
                                    {...keyPayer.form([
                                        project.uuid,
                                        aiKey.uuid,
                                    ])}
                                    options={{ preserveScroll: true }}
                                    className="flex flex-wrap items-end gap-3 p-4"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                label={t('ai.key.payer')}
                                                htmlFor="ai-key-payer-select"
                                                error={errors.payer}
                                                className="min-w-0 flex-1"
                                            >
                                                <Select
                                                    id="ai-key-payer-select"
                                                    name="payer"
                                                    defaultValue={
                                                        aiKey.payer ?? ''
                                                    }
                                                >
                                                    <option value="">
                                                        {t(
                                                            'ai.key.payer_inherit',
                                                            {
                                                                payer: t(
                                                                    `ai.enums.payer.${inherited}`,
                                                                ),
                                                            },
                                                        )}
                                                    </option>
                                                    <option value="us">
                                                        {t('ai.enums.payer.us')}
                                                    </option>
                                                    <option value="customer">
                                                        {t(
                                                            'ai.enums.payer.customer',
                                                        )}
                                                    </option>
                                                </Select>
                                            </Field>
                                            <Button
                                                type="submit"
                                                variant="primary"
                                                disabled={processing}
                                            >
                                                {t('ai.key.payer_save')}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            ) : (
                                <p className="p-4 text-[13px]">
                                    {t(
                                        `ai.enums.payer.${aiKey.effective_payer}`,
                                    )}
                                </p>
                            )}
                        </Card>
                    </div>
                </div>

                <Card aria-labelledby="ai-key-calls">
                    <CardHeader
                        id="ai-key-calls"
                        title={t('ai.key.calls')}
                        description={t('ai.key.calls_description')}
                        aside={
                            <label className="flex items-center gap-2 text-xs text-ink-2">
                                <Switch
                                    small
                                    checked={filters.errors}
                                    onChange={showErrors}
                                    label={t('ai.key.only_errors')}
                                />
                                <span aria-hidden="true">
                                    {t('ai.key.only_errors')}
                                </span>
                            </label>
                        }
                    />
                    {calls.length === 0 ? (
                        <EmptyState title={t('ai.key.no_calls')} />
                    ) : (
                        <div className="relative overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('ai.columns.time')}
                                        </th>
                                        <th className={thClass}>
                                            {t('ai.columns.model')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'hidden md:table-cell',
                                            )}
                                        >
                                            {t('ai.columns.operation')}
                                        </th>
                                        <th className={thClass}>
                                            {t('ai.columns.status')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('ai.columns.input')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('ai.columns.output')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'hidden text-right lg:table-cell',
                                            )}
                                        >
                                            {t('ai.columns.cached')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'hidden text-right lg:table-cell',
                                            )}
                                        >
                                            {t('ai.columns.duration')}
                                        </th>
                                        <th className={thClass}>
                                            {t('ai.columns.actor')}
                                        </th>
                                        <th className={thClass}>
                                            <span className="sr-only">
                                                {t('traces.open')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {calls.map((call) => (
                                        <tr
                                            key={call.id}
                                            className="hover:bg-panel-2/60"
                                        >
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'font-mono text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                <time dateTime={call.at}>
                                                    {formatDateTime(call.at)}
                                                </time>
                                            </td>
                                            <td className={tdClass}>
                                                <Mono className="text-xs">
                                                    {call.model}
                                                </Mono>
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'hidden text-xs text-ink-2 md:table-cell',
                                                )}
                                            >
                                                {t(
                                                    `ai.enums.operation.${call.operation}`,
                                                )}
                                            </td>
                                            <td className={tdClass}>
                                                <Badge
                                                    tone={
                                                        call.status === 'error'
                                                            ? 'err'
                                                            : 'ok'
                                                    }
                                                    title={
                                                        call.error ?? undefined
                                                    }
                                                >
                                                    {t(
                                                        `ai.enums.status.${call.status}`,
                                                    )}
                                                </Badge>
                                                {call.error && (
                                                    <span className="ml-2 font-mono text-[11px] text-ink-3">
                                                        {call.error}
                                                    </span>
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {tokens(call.input_tokens)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {tokens(call.output_tokens)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                    'hidden lg:table-cell',
                                                )}
                                            >
                                                {tokens(call.cache_read_tokens)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                    'hidden lg:table-cell',
                                                )}
                                            >
                                                {call.duration_ms === null
                                                    ? '-'
                                                    : `${formatNumber(call.duration_ms)} ms`}
                                            </td>
                                            <td className={tdClass}>
                                                {call.actor ? (
                                                    <Link
                                                        href={
                                                            actorShow(
                                                                project.uuid,
                                                                {
                                                                    query: {
                                                                        ...query,
                                                                        ...call.actor,
                                                                    },
                                                                },
                                                            ).url
                                                        }
                                                        className="font-mono text-xs hover:text-amber hover:underline"
                                                    >
                                                        {referenceLabel(
                                                            call.actor,
                                                        )}
                                                    </Link>
                                                ) : (
                                                    <span className="text-ink-3">
                                                        -
                                                    </span>
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-right',
                                                )}
                                            >
                                                {call.trace_id && (
                                                    <Link
                                                        href={
                                                            traceShow([
                                                                project.uuid,
                                                                call.trace_id,
                                                            ]).url
                                                        }
                                                        className="inline-block py-1 text-xs whitespace-nowrap text-ink-2 hover:text-amber hover:underline"
                                                    >
                                                        {t('traces.open')}
                                                    </Link>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                {(prev_url || next_url) && (
                    <nav
                        aria-label={t('ai.key.calls')}
                        className="flex justify-end gap-2"
                    >
                        {prev_url && (
                            <Link href={prev_url} className={pagerClass}>
                                {t('ai.key.newer')}
                            </Link>
                        )}
                        {next_url && (
                            <Link href={next_url} className={pagerClass}>
                                {t('ai.key.older')}
                            </Link>
                        )}
                    </nav>
                )}
            </PageBody>
        </>
    );
}
