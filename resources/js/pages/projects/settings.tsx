import { Relative } from '@/components/lj/relative';
import {
    RetentionSaveBar,
    RetentionTable,
    retentionFormData,
    retentionPayload,
} from '@/components/lj/retention-table';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ChannelsCard, RulesCard } from '@/components/lj/alerts';
import type { Channel as AlertChannel } from '@/components/lj/alerts';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { CopyButton } from '@/components/lj/copy-text';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { destroy as agentDestroy } from '@/routes/agents';
import { destroy as sourceMapDestroy } from '@/routes/source-maps';
import { LABEL_COLORS, LabelChip } from '@/components/lj/labels';
import type { IssueLabel } from '@/components/lj/labels';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
    buttonClass,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    Label,
    PageTitle,
    Select,
    Sparkline,
    spreadTips,
    tableClass,
    tdClass,
    thClass,
    numClass,
    navActiveClass,
    Switch,
} from '@/components/lj/ui';
import { LiveNotice } from '@/components/lj/live-notice';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatBytes, formatNumber, formatPercent } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { useFormSync, useTeamLive } from '@/lib/use-live';
import { useProjectLive } from '@/lib/use-project-live';
import { cn } from '@/lib/utils';
import {
    destroy as channelDestroy,
    store as channelStore,
} from '@/routes/log-channels';
import {
    destroy as projectDestroy,
    retention as retentionUpdate,
    publicStats,
    rotatePublicKey,
    rotateStatsKey,
    rotateToken,
    show as projectShow,
} from '@/routes/projects';
import {
    destroy as labelDestroy,
    update as labelUpdate,
} from '@/routes/labels';
import { index as dataSourcesIndex } from '@/routes/data-sources';
import { update as settingsUpdate } from '@/routes/projects/settings';
import { show as teamShow } from '@/routes/team';
import { index as jobsIndex } from '@/routes/jobs';
import {
    destroy as repositoryDestroy,
    test as repositoryTest,
    update as repositoryUpdate,
} from '@/routes/repository';
import type {
    ProjectAlertRule,
    ProjectHeader,
    RetentionRow,
    RetentionStats,
    TeamShared,
} from '@/types/lumberjack';

type Settings = {
    name: string;
    slug: string;
    issue_prefix: string;
    allowed_origins: string[];
    scrub_keys: string[];
    default_scrub_keys: string[];
    token_last_four: string | null;
    public_key: string;
    ingest_url: string;
    browser_sdk_url: string;
    sourcemaps_url: string;
    server_url: string;
    /** The metrics API under the public stats key; null while public stats are off. */
    stats_url: string | null;
    stats_include_titles: boolean;
};

type Agent = {
    id: number;
    host: string;
    name: string | null;
    client: string | null;
    client_parts: { part: string; name: string; version: string | null }[];
    last_seen_at: string;
    online: boolean;
    metrics: HostMetrics | null;
};
type HostMetrics = {
    sampled_at: string;
    cpu_load_1m: number | null;
    cpu_cores: number | null;
    memory_used_bytes: number | null;
    memory_total_bytes: number | null;
    disk_used_bytes: number | null;
    disk_total_bytes: number | null;
    php_memory_peak_bytes: number | null;
    /** Highest share of memory in use per hour over the last day. */
    memory_series: number[];
    /** Highest 1 minute load per hour over the last day. */
    load_series: number[];
};
type Channel = {
    id: number;
    name: string;
    is_default: boolean;
    lines_24h: number;
    retention_days: number | null;
};
type SourceMap = {
    id: number;
    path: string;
    release: string | null;
    size: number;
    uploaded_at: string;
};
type JobOverride = { job_class: string; days: number };
type Repository = {
    provider: string;
    repository: string;
    token_expires_at: string | null;
    labels: string[];
    allow_public: boolean;
} | null;

type Props = {
    project: ProjectHeader;
    settings: Settings;
    agents: Agent[];
    source_maps: SourceMap[];
    channels: Channel[];
    labels: (IssueLabel & { issues: number })[];
    unknown_channels: string[];
    retention: RetentionRow[];
    /** Deferred: counted after the page shows. */
    retention_stats?: RetentionStats;
    job_overrides: JobOverride[];
    repository: Repository;
    triage: TriageProvider[];
    alerts: {
        channels: AlertChannel[];
        available: AlertChannel[];
        types: string[];
        rules: ProjectAlertRule[];
        queue_thresholds: {
            connection: string;
            queue: string;
            size: number | null;
            oldest_minutes: number | null;
            sustained_minutes: number | null;
            muted: boolean;
        }[];
    };
};

type TriageProvider = {
    key: string;
    name: string;
    path: string;
    secrets: string[];
    workflow: string;
    local: string;
};

const CHANNEL_PATTERN = /^[a-z0-9]+(-[a-z0-9]+)*$/;
const SECTIONS = [
    'general',
    'install',
    'keys',
    'agents',
    'sourcemaps',
    'channels',
    'labels',
    'retention',
    'alerts',
    'repository',
    'triage',
    'danger',
] as const;

const kebab = (value: string): string =>
    value
        .trim()
        .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 64)
        .replace(/-+$/, '');

const errorsFor = (errors: object, prefix: string): string[] =>
    Object.entries(errors as Record<string, string>)
        .filter(([key]) => key === prefix || key.startsWith(`${prefix}.`))
        .map(([, message]) => message);

const daysOrNull = (value: string): number | null =>
    value.trim() === '' ? null : Number(value);

function Section({
    id,
    title,
    description,
    danger,
    children,
}: {
    id: string;
    title: string;
    description?: string;
    danger?: boolean;
    children: ReactNode;
}) {
    return (
        <Card
            id={`section-${id}`}
            aria-labelledby={`h-${id}`}
            className={cn('scroll-mt-6', danger && 'border-err-line')}
        >
            <CardHeader
                id={`h-${id}`}
                title={title}
                description={description}
            />
            <div className="flex flex-col gap-4 p-4">{children}</div>
        </Card>
    );
}

function Code({ label, children }: { label: string; children: string }) {
    return (
        <div className="flex flex-col gap-1.5">
            <span className="text-[13px] font-medium">{label}</span>
            <pre className="overflow-x-auto rounded-md border border-line bg-ground px-3.5 py-3 font-mono text-xs leading-5 text-ink-2">
                {children}
            </pre>
        </div>
    );
}

type SectionKey = (typeof SECTIONS)[number];

// Per project: another project's last section (say, the danger zone) is no place to open on.
const storedSectionKey = (project: string) =>
    `lumberjack.settings.section.${project}`;

const isSection = (value: string | null): value is SectionKey =>
    value !== null && (SECTIONS as readonly string[]).includes(value);

/**
 * The open section: from the address, else the one open before a save
 * redirected back without it, else the first.
 */
const sectionFromHash = (project: string, pageUrl = ''): SectionKey => {
    if (typeof window === 'undefined') {
        return 'general';
    }

    const hash = window.location.hash.slice(1);

    if (isSection(hash)) {
        return hash;
    }

    // ?section=install, from a redirect, which cannot carry #install. Read from
    // Inertia's page URL: the address bar may not show the redirect yet on the first render.
    const asked = new URL(
        pageUrl || window.location.href,
        window.location.origin,
    ).searchParams.get('section');

    if (isSection(asked)) {
        return asked;
    }

    try {
        const stored = window.sessionStorage.getItem(storedSectionKey(project));

        return isSection(stored) ? stored : 'general';
    } catch {
        return 'general';
    }
};

export default function ProjectSettings(props: Props) {
    const t = useT();
    const uuid = props.project.uuid;
    const pageUrl = usePage().url;
    const [active, setActive] = useState<SectionKey>(() =>
        sectionFromHash(uuid, pageUrl),
    );

    // Back and forward between sections, and links to #section on this page.
    useEffect(() => {
        const onHashChange = () => setActive(sectionFromHash(uuid));
        window.addEventListener('hashchange', onHashChange);

        return () => window.removeEventListener('hashchange', onHashChange);
    }, [uuid]);

    // Keep the open section in the address and for this tab, so a save that
    // redirects back opens it again.
    useEffect(() => {
        try {
            window.sessionStorage.setItem(storedSectionKey(uuid), active);
        } catch {
            // Storage can be refused; the address still carries the section.
        }

        if (
            window.location.hash.slice(1) !== active ||
            window.location.search !== ''
        ) {
            window.history.replaceState(
                window.history.state,
                '',
                `${window.location.pathname}#${active}`,
            );
        }
    }, [active, uuid]);
    const { team } = usePage<{ team: TeamShared }>().props;
    const { project } = props;
    // The forms here are filled from these props: while one has unsaved edits, a
    // change made elsewhere is announced instead of loaded.
    const live = [
        useProjectLive(
            uuid,
            ['settings.changed'],
            [
                'project',
                'settings',
                'labels',
                'channels',
                'unknown_channels',
                'retention',
                'job_overrides',
                'repository',
                'alerts',
            ],
            { guard: true },
        ),
        useProjectLive(uuid, ['agents.changed'], ['agents']),
        // The team's channels, rules and retention show here as what the project inherits.
        useTeamLive(
            ['alerts.changed', 'retention.changed'],
            ['alerts', 'retention'],
            { guard: true },
        ),
    ];

    return (
        <>
            <Head title={`${t('settings.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('settings.title') },
                ]}
            />
            <PageBody>
                <PageTitle title={t('settings.title')} />
                <div className="grid items-start gap-5 lg:grid-cols-[180px_minmax(0,1fr)]">
                    <nav
                        aria-label={t('settings.toc')}
                        className="flex min-w-0 gap-1 whitespace-nowrap max-lg:overflow-x-auto max-lg:pb-1 lg:sticky lg:top-6 lg:flex-col"
                    >
                        {SECTIONS.map((section) => [
                            // The data sources are a page of their own, listed with the settings.
                            section === 'danger' && (
                                <Link
                                    key="data-sources"
                                    href={dataSourcesIndex(project.uuid).url}
                                    className="rounded-md px-2.5 py-1.5 text-ink-2 hover:bg-panel-2 hover:text-ink"
                                >
                                    {t('common.nav.data_sources')}
                                </Link>
                            ),
                            section === 'danger' && (
                                <span
                                    key="danger-separator"
                                    aria-hidden="true"
                                    className="mx-1.5 w-px shrink-0 self-stretch bg-line lg:mx-0 lg:my-2 lg:h-px lg:w-auto"
                                />
                            ),
                            <a
                                key={section}
                                href={`#${section}`}
                                aria-current={
                                    active === section ? 'page' : undefined
                                }
                                onClick={(e) => {
                                    // Switch section without the browser jumping to it.
                                    e.preventDefault();
                                    setActive(section);
                                }}
                                className={cn(
                                    'lj-nav-item rounded-md px-2.5 py-1.5 text-ink-2 hover:bg-panel-2 hover:text-ink',
                                    active === section && navActiveClass,
                                    section === 'danger' && 'text-err',
                                )}
                            >
                                {t(`settings.${section}.title`)}
                            </a>,
                        ])}
                    </nav>
                    {/* One section at a time; the address keeps which, so a link can open it. */}
                    <div className="flex min-w-0 flex-col gap-5">
                        {active === 'general' && <GeneralSection {...props} />}
                        {active === 'install' && <InstallSection {...props} />}
                        {active === 'keys' && <KeysSection {...props} />}
                        {active === 'agents' && <AgentsSection {...props} />}
                        {active === 'sourcemaps' && (
                            <SourceMapsSection {...props} />
                        )}
                        {active === 'channels' && (
                            <ChannelsSection {...props} />
                        )}
                        {active === 'labels' && <LabelsSection {...props} />}
                        {active === 'retention' && (
                            <RetentionSection {...props} />
                        )}
                        {active === 'alerts' && <AlertsSection {...props} />}
                        {active === 'repository' && (
                            <RepositorySection {...props} />
                        )}
                        {active === 'triage' && <TriageSection {...props} />}
                        {active === 'danger' && <DangerSection {...props} />}
                    </div>
                </div>
            </PageBody>
            <LiveNotice live={live} />
        </>
    );
}

function GeneralSection({ project, settings }: Props) {
    const t = useT();
    const initial = {
        name: settings.name,
        slug: settings.slug,
        issue_prefix: settings.issue_prefix,
        allowed_origins: settings.allowed_origins,
        scrub_keys: settings.scrub_keys,
    };
    const form = useForm(initial);
    useFormSync(form, initial);
    const [scrubKey, setScrubKey] = useState('');
    const [scrubError, setScrubError] = useState<string | null>(null);

    const addScrubKey = () => {
        const value = scrubKey.trim().toLowerCase();

        if (
            !/^[a-z0-9_.*-]{1,64}$/.test(value) ||
            value.replace(/[*.]/g, '') === ''
        ) {
            setScrubError(t('settings.general.scrub_invalid'));

            return;
        }

        if (
            form.data.scrub_keys.includes(value) ||
            settings.default_scrub_keys.includes(value)
        ) {
            setScrubError(t('settings.general.scrub_duplicate'));

            return;
        }

        form.setData('scrub_keys', [...form.data.scrub_keys, value]);
        setScrubKey('');
        setScrubError(null);
    };

    const scrubErrors = errorsFor(form.errors, 'scrub_keys');
    const [origin, setOrigin] = useState('');
    const [originError, setOriginError] = useState<string | null>(null);

    const addOrigin = () => {
        const value = origin.trim().replace(/\/+$/, '');

        if (!/^https?:\/\/[^\s/]+$/.test(value)) {
            setOriginError(t('settings.general.origin_invalid'));

            return;
        }

        if (form.data.allowed_origins.includes(value)) {
            setOriginError(t('settings.general.origin_duplicate'));

            return;
        }

        form.setData('allowed_origins', [...form.data.allowed_origins, value]);
        setOrigin('');
        setOriginError(null);
    };

    const originErrors = errorsFor(form.errors, 'allowed_origins');

    return (
        <Section id="general" title={t('settings.general.title')}>
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.submit(settingsUpdate(project.uuid), {
                        preserveScroll: true,
                    });
                }}
            >
                <Field
                    label={t('settings.general.name')}
                    htmlFor="project-name"
                    error={form.errors.name}
                >
                    <Input
                        id="project-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                        maxLength={100}
                    />
                </Field>
                <Field
                    label={t('settings.general.slug')}
                    htmlFor="project-slug"
                    hint={t('settings.general.slug_hint')}
                    error={form.errors.slug}
                >
                    <Input
                        id="project-slug"
                        className="font-mono"
                        value={form.data.slug}
                        onChange={(e) => form.setData('slug', e.target.value)}
                        required
                        maxLength={100}
                    />
                </Field>
                <Field
                    label={t('settings.general.issue_prefix')}
                    htmlFor="project-issue-prefix"
                    hint={t('settings.general.issue_prefix_hint', {
                        example: `${form.data.issue_prefix || 'DEMO'}-42`,
                    })}
                    error={form.errors.issue_prefix}
                >
                    <Input
                        id="project-issue-prefix"
                        className="w-40 font-mono uppercase"
                        value={form.data.issue_prefix}
                        onChange={(e) =>
                            form.setData(
                                'issue_prefix',
                                e.target.value
                                    .toUpperCase()
                                    .replace(/[^A-Z0-9]/g, ''),
                            )
                        }
                        required
                        minLength={2}
                        maxLength={8}
                        spellCheck={false}
                    />
                </Field>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="project-origin">
                        {t('settings.general.origins')}
                    </Label>
                    {form.data.allowed_origins.length > 0 ? (
                        <ul className="flex flex-wrap gap-1.5">
                            {form.data.allowed_origins.map((value) => (
                                <li key={value}>
                                    <Badge className="h-6 font-mono">
                                        {value}
                                        <button
                                            type="button"
                                            className="cursor-pointer rounded text-ink-3 hover:text-ink focus-visible:outline-2 focus-visible:outline-amber"
                                            onClick={() =>
                                                form.setData(
                                                    'allowed_origins',
                                                    form.data.allowed_origins.filter(
                                                        (o) => o !== value,
                                                    ),
                                                )
                                            }
                                        >
                                            <X
                                                className="size-3"
                                                aria-hidden="true"
                                            />
                                            <span className="sr-only">
                                                {t(
                                                    'settings.general.remove_origin',
                                                    { origin: value },
                                                )}
                                            </span>
                                        </button>
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-xs text-ink-3">
                            {t('settings.general.no_origins')}
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Input
                            id="project-origin"
                            type="url"
                            className="min-w-0 flex-1 font-mono"
                            placeholder="https://"
                            value={origin}
                            aria-invalid={originError !== null}
                            aria-describedby="project-origin-hint"
                            onChange={(e) => {
                                setOrigin(e.target.value);
                                setOriginError(null);
                            }}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    addOrigin();
                                }
                            }}
                        />
                        <Button onClick={addOrigin}>
                            {t('settings.general.add_origin')}
                        </Button>
                    </div>
                    <p
                        id="project-origin-hint"
                        className={cn(
                            'text-xs',
                            originError || originErrors.length
                                ? 'text-err'
                                : 'text-ink-3',
                        )}
                    >
                        {originError ??
                            originErrors[0] ??
                            t('settings.general.origins_hint')}
                    </p>
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="project-scrub-key">
                        {t('settings.general.scrub_keys')}
                    </Label>
                    <p className="text-xs text-ink-3">
                        {t('settings.general.scrub_defaults')}{' '}
                        <span className="font-mono">
                            {settings.default_scrub_keys.join(', ')}
                        </span>
                    </p>
                    {form.data.scrub_keys.length > 0 && (
                        <ul className="flex flex-wrap gap-1.5">
                            {form.data.scrub_keys.map((value) => (
                                <li key={value}>
                                    <Badge className="h-6 font-mono">
                                        {value}
                                        <button
                                            type="button"
                                            className="cursor-pointer rounded text-ink-3 hover:text-ink focus-visible:outline-2 focus-visible:outline-amber"
                                            onClick={() =>
                                                form.setData(
                                                    'scrub_keys',
                                                    form.data.scrub_keys.filter(
                                                        (k) => k !== value,
                                                    ),
                                                )
                                            }
                                        >
                                            <X
                                                className="size-3"
                                                aria-hidden="true"
                                            />
                                            <span className="sr-only">
                                                {t(
                                                    'settings.general.remove_scrub_key',
                                                    { key: value },
                                                )}
                                            </span>
                                        </button>
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Input
                            id="project-scrub-key"
                            className="min-w-0 flex-1 font-mono"
                            placeholder="cpr, card_*, session.checkout.*"
                            value={scrubKey}
                            aria-invalid={scrubError !== null}
                            aria-describedby="project-scrub-hint"
                            onChange={(e) => {
                                setScrubKey(e.target.value);
                                setScrubError(null);
                            }}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    addScrubKey();
                                }
                            }}
                        />
                        <Button onClick={addScrubKey}>
                            {t('settings.general.add_scrub_key')}
                        </Button>
                    </div>
                    <p
                        id="project-scrub-hint"
                        className={cn(
                            'text-xs',
                            scrubError || scrubErrors.length
                                ? 'text-err'
                                : 'text-ink-3',
                        )}
                    >
                        {scrubError ??
                            scrubErrors[0] ??
                            t('settings.general.scrub_hint')}
                    </p>
                </div>
                <div>
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </Button>
                </div>
            </form>
        </Section>
    );
}

function InstallSection({ settings }: Props) {
    const t = useT();
    const token = `lj_srv_${'•'.repeat(12)}${settings.token_last_four ?? ''}`;

    return (
        <Section
            id="install"
            title={t('settings.install.title')}
            description={t('settings.install.description')}
        >
            <Code label={t('settings.install.step_package')}>
                composer require exeque/laravel-lumberjack
            </Code>
            <Code
                label={t('settings.install.step_env')}
            >{`LUMBERJACK_URL=${settings.server_url}\nLUMBERJACK_TOKEN=${token}\nLUMBERJACK_PUBLIC_KEY=${settings.public_key}`}</Code>
            <Code label={t('settings.install.step_agent')}>
                php artisan lumberjack:agent
            </Code>
            <Code label={t('settings.install.step_browser')}>@lumberjack</Code>
            <Code label={t('settings.install.step_test')}>
                php artisan lumberjack:test
            </Code>
            <Code
                label={t('settings.install.step_browser_plain')}
            >{`<script src="${settings.browser_sdk_url}" data-key="${settings.public_key}" data-environment="production" defer></script>`}</Code>
        </Section>
    );
}

function KeysSection({ project, settings }: Props) {
    const t = useT();

    return (
        <Section id="keys" title={t('settings.keys.title')}>
            <Field
                label={t('settings.keys.server_token')}
                htmlFor="server-token"
                hint={t('settings.keys.server_token_hint')}
            >
                <div className="flex flex-wrap gap-2">
                    <Input
                        id="server-token"
                        readOnly
                        className="min-w-0 flex-1 font-mono"
                        value={`lj_srv_${'•'.repeat(12)}${settings.token_last_four ?? ''}`}
                    />
                    <ConfirmButton
                        variant="default"
                        question={t('settings.keys.rotate_token_question')}
                        confirmLabel={t('settings.keys.rotate')}
                        onConfirm={() =>
                            router.post(
                                rotateToken(project.uuid).url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {t('settings.keys.rotate')}
                    </ConfirmButton>
                </div>
            </Field>
            <Field
                label={t('settings.keys.public_key')}
                htmlFor="public-key"
                hint={t('settings.keys.public_key_hint')}
            >
                <div className="flex flex-wrap gap-2">
                    <Input
                        id="public-key"
                        readOnly
                        className="min-w-0 flex-1 font-mono"
                        value={settings.public_key}
                        onFocus={(e) => e.currentTarget.select()}
                    />
                    <CopyButton text={settings.public_key} />
                    <ConfirmButton
                        variant="default"
                        question={t('settings.keys.rotate_public_question')}
                        confirmLabel={t('settings.keys.rotate')}
                        onConfirm={() =>
                            router.post(
                                rotatePublicKey(project.uuid).url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {t('settings.keys.rotate')}
                    </ConfirmButton>
                </div>
            </Field>
            <PublicStatsField project={project} settings={settings} />
        </Section>
    );
}

function PublicStatsField({
    project,
    settings,
}: Pick<Props, 'project' | 'settings'>) {
    const t = useT();
    const save = (enabled: boolean, includeTitles: boolean) =>
        router.put(
            publicStats(project.uuid).url,
            { enabled, include_titles: includeTitles },
            { preserveScroll: true },
        );

    return (
        <Field
            label={t('settings.keys.public_stats')}
            htmlFor="public-stats"
            hint={t('settings.keys.public_stats_hint')}
        >
            <div className="flex flex-col gap-3">
                <div className="flex items-center gap-2">
                    <Switch
                        id="public-stats"
                        small
                        checked={settings.stats_url !== null}
                        onChange={(on) =>
                            save(on, settings.stats_include_titles)
                        }
                        label={t('settings.keys.public_stats_enable')}
                    />
                    <Label htmlFor="public-stats">
                        {t('settings.keys.public_stats_enable')}
                    </Label>
                </div>
                {settings.stats_url !== null && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <Input
                                id="public-stats-url"
                                aria-label={t('settings.keys.public_stats_url')}
                                readOnly
                                className="min-w-0 flex-1 font-mono"
                                value={settings.stats_url}
                                onFocus={(e) => e.currentTarget.select()}
                            />
                            <CopyButton text={settings.stats_url} />
                            <ConfirmButton
                                variant="default"
                                question={t(
                                    'settings.keys.rotate_stats_question',
                                )}
                                confirmLabel={t('settings.keys.rotate')}
                                onConfirm={() =>
                                    router.post(
                                        rotateStatsKey(project.uuid).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('settings.keys.rotate')}
                            </ConfirmButton>
                        </div>
                        <div className="flex items-center gap-2">
                            <Switch
                                id="public-stats-titles"
                                small
                                checked={settings.stats_include_titles}
                                onChange={(on) => save(true, on)}
                                label={t('settings.keys.public_stats_titles')}
                            />
                            <Label htmlFor="public-stats-titles">
                                {t('settings.keys.public_stats_titles')}
                            </Label>
                        </div>
                    </>
                )}
            </div>
        </Field>
    );
}

/** Past this share of memory or disk in use, the value shows as a warning. */
const HOST_WARN = 0.9;

function HostCells({ metrics }: { metrics: HostMetrics | null }) {
    const t = useT();
    const share = (used: number | null, total: number | null) =>
        used !== null && total ? used / total : null;
    const memory = share(
        metrics?.memory_used_bytes ?? null,
        metrics?.memory_total_bytes ?? null,
    );
    const disk = share(
        metrics?.disk_used_bytes ?? null,
        metrics?.disk_total_bytes ?? null,
    );
    const load = metrics?.cpu_load_1m ?? null;
    const cores = metrics?.cpu_cores ?? null;
    const of = (used: number | null, total: number | null) =>
        used !== null && total !== null
            ? t('settings.agents.of', {
                  used: formatBytes(used),
                  total: formatBytes(total),
              })
            : undefined;
    const trend = (
        values: number[],
        label: string,
        format: (v: number) => string,
    ) =>
        values.some((v) => v > 0) && (
            <Sparkline
                values={values}
                width={64}
                height={18}
                color="var(--lj-info)"
                tips={spreadTips(values, '24h', format)}
                label={label}
            />
        );

    return (
        <>
            <td className={tdClass}>
                <span className="flex items-center gap-2">
                    <span
                        className={cn(
                            'font-mono text-xs',
                            load !== null &&
                                cores !== null &&
                                load > cores &&
                                'text-warn',
                        )}
                        title={t('settings.agents.load_hint')}
                    >
                        {load === null
                            ? '-'
                            : cores === null
                              ? formatNumber(load, 2)
                              : t('settings.agents.load', {
                                    load: formatNumber(load, 2),
                                    cores: formatNumber(
                                        cores,
                                        cores % 1 ? 1 : 0,
                                    ),
                                })}
                    </span>
                    {trend(
                        metrics?.load_series ?? [],
                        t('settings.agents.load_trend'),
                        (v) => formatNumber(v, 2),
                    )}
                </span>
            </td>
            <td className={tdClass}>
                <span className="flex items-center gap-2">
                    <span
                        className={cn(
                            'font-mono text-xs',
                            memory !== null &&
                                memory > HOST_WARN &&
                                'text-warn',
                        )}
                        title={of(
                            metrics?.memory_used_bytes ?? null,
                            metrics?.memory_total_bytes ?? null,
                        )}
                    >
                        {memory === null ? '-' : formatPercent(memory, 0)}
                    </span>
                    {trend(
                        metrics?.memory_series ?? [],
                        t('settings.agents.memory_trend'),
                        (v) => formatPercent(v, 0),
                    )}
                </span>
            </td>
            <td
                className={cn(
                    tdClass,
                    'font-mono text-xs',
                    disk !== null && disk > HOST_WARN && 'text-warn',
                )}
                title={of(
                    metrics?.disk_used_bytes ?? null,
                    metrics?.disk_total_bytes ?? null,
                )}
            >
                {disk === null ? '-' : formatPercent(disk, 0)}
            </td>
        </>
    );
}

function AgentsSection({ project, agents }: Props) {
    const t = useT();

    return (
        <Section
            id="agents"
            title={t('settings.agents.title')}
            description={t('settings.agents.description', {
                hours: 24,
            })}
        >
            {agents.length === 0 ? (
                <EmptyState title={t('settings.agents.empty')} />
            ) : (
                <div className="overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                <th className={thClass}>
                                    {t('settings.agents.name')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.agents.host')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.agents.client')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.agents.cpu')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.agents.memory')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.agents.disk')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.agents.last_seen')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.agents.status')}
                                </th>
                                {project.can_administer && (
                                    <th className={thClass}>
                                        <span className="sr-only">
                                            {t('settings.agents.actions')}
                                        </span>
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {agents.map((agent) => (
                                <tr key={`${agent.host}-${agent.name ?? ''}`}>
                                    <td className={tdClass}>
                                        {agent.name ?? (
                                            <span className="text-ink-3">
                                                -
                                            </span>
                                        )}
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            'font-mono text-xs',
                                        )}
                                    >
                                        {agent.host}
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            'font-mono text-xs',
                                        )}
                                    >
                                        {agent.client_parts.length > 0 ? (
                                            <Tooltip>
                                                <TooltipTrigger className="cursor-help underline decoration-line-strong decoration-dotted underline-offset-4">
                                                    {agent.client ?? '-'}
                                                </TooltipTrigger>
                                                <TooltipContent className="border border-line-strong bg-panel-2 px-3 py-2 text-ink shadow-lg [&_svg]:bg-panel-2 [&_svg]:fill-panel-2">
                                                    <dl className="grid grid-cols-[auto_auto_auto] gap-x-3 gap-y-1 font-mono text-[11px]">
                                                        {agent.client_parts.map(
                                                            (p) => (
                                                                <div
                                                                    key={p.part}
                                                                    className="contents"
                                                                >
                                                                    <dt className="font-sans text-ink-3">
                                                                        {t(
                                                                            `settings.agents.parts.${p.part}`,
                                                                        )}
                                                                    </dt>
                                                                    <dd>
                                                                        {p.name}
                                                                    </dd>
                                                                    <dd className="text-ink-2">
                                                                        {p.version ??
                                                                            '-'}
                                                                    </dd>
                                                                </div>
                                                            ),
                                                        )}
                                                    </dl>
                                                </TooltipContent>
                                            </Tooltip>
                                        ) : (
                                            (agent.client ?? '-')
                                        )}
                                    </td>
                                    <HostCells metrics={agent.metrics} />
                                    <td className={tdClass}>
                                        <Relative iso={agent.last_seen_at} />
                                    </td>
                                    <td className={tdClass}>
                                        <Badge
                                            tone={agent.online ? 'ok' : 'warn'}
                                        >
                                            {agent.online
                                                ? t('settings.agents.online')
                                                : t('settings.agents.offline')}
                                        </Badge>
                                    </td>
                                    {project.can_administer && (
                                        <td
                                            className={cn(
                                                tdClass,
                                                'text-right',
                                            )}
                                        >
                                            <ConfirmButton
                                                question={t(
                                                    'settings.agents.remove_question',
                                                    { host: agent.host },
                                                )}
                                                confirmLabel={t(
                                                    'settings.agents.remove',
                                                )}
                                                onConfirm={() =>
                                                    router.delete(
                                                        agentDestroy([
                                                            project.uuid,
                                                            agent.id,
                                                        ]).url,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('settings.agents.remove')}
                                                <span className="sr-only">
                                                    {' '}
                                                    {agent.host}
                                                </span>
                                            </ConfirmButton>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Section>
    );
}

function SourceMapsSection({ project, settings, source_maps }: Props) {
    const t = useT();

    return (
        <Section
            id="sourcemaps"
            title={t('settings.sourcemaps.title')}
            description={t('settings.sourcemaps.description')}
        >
            <Code label={t('settings.sourcemaps.upload_url')}>
                {settings.sourcemaps_url}
            </Code>
            {source_maps.length === 0 ? (
                <EmptyState title={t('settings.sourcemaps.empty')} />
            ) : (
                <div className="overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                <th className={thClass}>
                                    {t('settings.sourcemaps.path')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.sourcemaps.release')}
                                </th>
                                <th className={cn(thClass, numClass)}>
                                    {t('settings.sourcemaps.size')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.sourcemaps.uploaded_at')}
                                </th>
                                {project.can_administer && (
                                    <th className={thClass}>
                                        <span className="sr-only">
                                            {t('settings.sourcemaps.actions')}
                                        </span>
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {source_maps.map((map) => (
                                <tr key={map.id}>
                                    <td
                                        className={cn(
                                            tdClass,
                                            'font-mono text-xs break-all',
                                        )}
                                    >
                                        {map.path}
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            'font-mono text-xs',
                                        )}
                                    >
                                        {map.release ?? (
                                            <span className="text-ink-3">
                                                -
                                            </span>
                                        )}
                                    </td>
                                    <td className={cn(tdClass, numClass)}>
                                        {formatBytes(map.size)}
                                    </td>
                                    <td className={tdClass}>
                                        <Relative iso={map.uploaded_at} />
                                    </td>
                                    {project.can_administer && (
                                        <td
                                            className={cn(
                                                tdClass,
                                                'text-right',
                                            )}
                                        >
                                            <ConfirmButton
                                                question={t(
                                                    'settings.sourcemaps.delete_question',
                                                    { path: map.path },
                                                )}
                                                confirmLabel={t(
                                                    'settings.sourcemaps.delete',
                                                )}
                                                onConfirm={() =>
                                                    router.delete(
                                                        sourceMapDestroy([
                                                            project.uuid,
                                                            map.id,
                                                        ]).url,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t(
                                                    'settings.sourcemaps.delete',
                                                )}
                                                <span className="sr-only">
                                                    {' '}
                                                    {map.path}
                                                </span>
                                            </ConfirmButton>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Section>
    );
}

function LabelRow({
    project,
    label,
}: {
    project: ProjectHeader;
    label: IssueLabel & { issues: number };
}) {
    const t = useT();
    const initial = { name: label.name, color: label.color };
    const form = useForm(initial);
    useFormSync(form, initial);

    return (
        <li className="flex flex-wrap items-center gap-2 border-t border-line py-2.5 first:border-t-0">
            <span className="w-36 shrink-0">
                <LabelChip label={form.data} />
            </span>
            <label className="sr-only" htmlFor={`label-name-${label.id}`}>
                {t('settings.labels.name')}
            </label>
            <Input
                id={`label-name-${label.id}`}
                value={form.data.name}
                maxLength={32}
                onChange={(e) => form.setData('name', e.target.value)}
                className="min-w-0 flex-[1_1_160px]"
            />
            <label className="sr-only" htmlFor={`label-color-${label.id}`}>
                {t('settings.labels.color')}
            </label>
            <Select
                id={`label-color-${label.id}`}
                value={form.data.color}
                onChange={(e) => form.setData('color', e.target.value)}
            >
                {LABEL_COLORS.map((color) => (
                    <option key={color} value={color}>
                        {t(`settings.labels.colors.${color}`)}
                    </option>
                ))}
            </Select>
            <span className="text-xs text-ink-3">
                {t('settings.labels.issues', {}, label.issues)}
            </span>
            <Button
                disabled={
                    form.processing ||
                    (form.data.name === label.name &&
                        form.data.color === label.color)
                }
                onClick={() =>
                    form.put(labelUpdate([project.uuid, label.id]).url, {
                        preserveScroll: true,
                    })
                }
            >
                {t('common.save')}
            </Button>
            <ConfirmButton
                question={t('settings.labels.delete_question', {
                    label: label.name,
                })}
                confirmLabel={t('settings.labels.delete')}
                onConfirm={() =>
                    router.delete(labelDestroy([project.uuid, label.id]).url, {
                        preserveScroll: true,
                    })
                }
            >
                {t('settings.labels.delete')}
            </ConfirmButton>
        </li>
    );
}

function LabelsSection({ project, labels }: Props) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <Section
            id="labels"
            title={t('settings.labels.title')}
            description={t('settings.labels.description')}
        >
            {labels.length === 0 ? (
                <EmptyState title={t('settings.labels.empty')} />
            ) : (
                <ul className="flex flex-col">
                    {labels.map((label) => (
                        <LabelRow
                            key={label.id}
                            project={project}
                            label={label}
                        />
                    ))}
                </ul>
            )}
            {errors.label && (
                <p className="text-xs text-err" role="alert">
                    {errors.label}
                </p>
            )}
        </Section>
    );
}

function ChannelsSection({
    project,
    channels,
    unknown_channels,
    retention,
}: Props) {
    const t = useT();
    const form = useForm({ name: '' });
    const inputRef = useRef<HTMLInputElement>(null);
    const [autoSubmit, setAutoSubmit] = useState(false);
    const logs = retention.find((r) => r.category === 'logs');
    const projectLogDays = logs?.effective.days ?? 0;
    const names = channels.map((c) => c.name);
    const unknown = unknown_channels.filter((name) => !names.includes(name));

    const validate = (value: string): string | null => {
        if (value === '') {
            return null;
        }

        if (value.length > 64) {
            return t('settings.channels.too_long');
        }

        if (value === 'default') {
            return t('settings.channels.reserved');
        }

        if (!CHANNEL_PATTERN.test(value)) {
            return t('settings.channels.invalid');
        }

        if (names.includes(value)) {
            return t('settings.channels.taken');
        }

        return null;
    };

    const name = form.data.name;
    const problem = validate(name);
    const suggestion = problem !== null ? kebab(name) : '';
    const canSuggest =
        suggestion !== '' &&
        suggestion !== name &&
        validate(suggestion) === null;
    const serverError = form.errors.name;

    const submit = () => {
        if (name === '' || validate(name) !== null) {
            return;
        }

        form.submit(channelStore(project.uuid), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    useEffect(() => {
        if (autoSubmit) {
            setAutoSubmit(false);
            submit();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [autoSubmit]);

    return (
        <Section
            id="channels"
            title={t('settings.channels.title')}
            description={t('settings.channels.description')}
        >
            <div className="overflow-x-auto">
                <table className={tableClass}>
                    <thead>
                        <tr>
                            <th className={thClass}>
                                {t('settings.channels.name')}
                            </th>
                            <th className={cn(thClass, 'text-right')}>
                                {t('settings.channels.lines')}
                            </th>
                            <th className={thClass}>
                                {t('settings.channels.retention')}
                            </th>
                            <th className={thClass}>
                                <span className="sr-only">
                                    {t('settings.actions')}
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {channels.map((channel) => (
                            <tr key={channel.id}>
                                <td
                                    className={cn(tdClass, 'font-mono text-xs')}
                                >
                                    <span className="inline-flex items-center gap-2">
                                        {channel.name}
                                        {channel.is_default && (
                                            <Badge>
                                                {t('settings.channels.locked')}
                                            </Badge>
                                        )}
                                    </span>
                                </td>
                                <td
                                    className={cn(tdClass, numClass, 'text-xs')}
                                >
                                    {formatNumber(channel.lines_24h)}
                                </td>
                                <td className={cn(tdClass, 'text-ink-2')}>
                                    {channel.retention_days !== null
                                        ? t(
                                              'settings.days',
                                              { count: channel.retention_days },
                                              channel.retention_days,
                                          )
                                        : t('settings.channels.inherits', {
                                              days: projectLogDays,
                                          })}
                                </td>
                                <td className={cn(tdClass, 'text-right')}>
                                    {channel.is_default ? (
                                        <span className="text-xs text-ink-3">
                                            {t(
                                                'settings.channels.cannot_delete',
                                            )}
                                        </span>
                                    ) : (
                                        <ConfirmButton
                                            question={t(
                                                'settings.channels.delete_question',
                                                { name: channel.name },
                                            )}
                                            confirmLabel={t('common.delete')}
                                            onConfirm={() =>
                                                router.delete(
                                                    channelDestroy({
                                                        project: project.uuid,
                                                        logChannel: channel.id,
                                                    }).url,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('common.delete')}
                                            <span className="sr-only">
                                                {' '}
                                                {channel.name}
                                            </span>
                                        </ConfirmButton>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {unknown.map((unknownName) => {
                const candidate = CHANNEL_PATTERN.test(unknownName)
                    ? unknownName
                    : kebab(unknownName);

                return (
                    <div
                        key={unknownName}
                        className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-warn-line bg-warn-bg px-3 py-2.5"
                    >
                        <span className="text-ink-2">
                            {t('settings.channels.unknown', {
                                name: unknownName,
                            })}
                        </span>
                        <Button
                            disabled={
                                form.processing ||
                                validate(candidate) !== null ||
                                candidate === ''
                            }
                            onClick={() => {
                                form.setData('name', candidate);
                                setAutoSubmit(true);
                            }}
                        >
                            {t('settings.channels.create_named', {
                                name: candidate,
                            })}
                        </Button>
                    </div>
                );
            })}

            <form
                className="flex flex-col gap-1.5"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <Label htmlFor="channel-name">
                    {t('settings.channels.new')}
                </Label>
                <div className="flex flex-wrap gap-2">
                    <Input
                        id="channel-name"
                        ref={inputRef}
                        className="min-w-0 flex-1 font-mono"
                        placeholder="example-log-channel"
                        autoComplete="off"
                        spellCheck={false}
                        value={name}
                        onChange={(e) => {
                            form.setData('name', e.target.value);
                            form.clearErrors('name');
                        }}
                        aria-invalid={
                            problem !== null || serverError !== undefined
                        }
                        aria-describedby="channel-name-hint"
                    />
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={
                            name === '' || problem !== null || form.processing
                        }
                    >
                        {t('settings.channels.create')}
                    </Button>
                </div>
                <p
                    id="channel-name-hint"
                    aria-live="polite"
                    className={cn(
                        'flex flex-wrap items-center gap-2 text-xs',
                        problem || serverError
                            ? 'text-err'
                            : name
                              ? 'text-ok'
                              : 'text-ink-3',
                    )}
                >
                    {problem ??
                        serverError ??
                        (name
                            ? t('settings.channels.valid')
                            : t('settings.channels.hint'))}
                    {canSuggest && (
                        <button
                            type="button"
                            className="cursor-pointer text-amber underline hover:brightness-110"
                            onClick={() => {
                                form.setData('name', suggestion);
                                inputRef.current?.focus();
                            }}
                        >
                            {t('settings.channels.use_suggestion', {
                                name: suggestion,
                            })}
                        </button>
                    )}
                </p>
            </form>
        </Section>
    );
}

function RetentionSection({
    project,
    retention,
    retention_stats,
    job_overrides,
    channels,
}: Props) {
    const t = useT();
    const initial = {
        ...retentionFormData(retention),
        job_overrides: job_overrides.map((o) => ({
            job_class: o.job_class,
            days: o.days.toString(),
        })),
        channels: Object.fromEntries(
            channels.map((c) => [c.id, c.retention_days?.toString() ?? '']),
        ) as Record<string, string>,
    };
    const form = useForm(initial);
    useFormSync(form, initial);
    const [jobClass, setJobClass] = useState('');
    const [jobDays, setJobDays] = useState('');
    const errors = form.errors as Record<string, string>;

    const addOverride = () => {
        if (jobClass.trim() === '' || jobDays.trim() === '') {
            return;
        }

        form.setData('job_overrides', [
            ...form.data.job_overrides.filter(
                (o) => o.job_class !== jobClass.trim(),
            ),
            { job_class: jobClass.trim(), days: jobDays },
        ]);
        setJobClass('');
        setJobDays('');
    };

    const save = () => {
        form.transform((data) => ({
            ...retentionPayload(data),
            job_overrides: data.job_overrides.map((o) => ({
                job_class: o.job_class,
                days: Number(o.days),
            })),
            channels: Object.fromEntries(
                Object.entries(data.channels).map(([key, value]) => [
                    key,
                    daysOrNull(value),
                ]),
            ),
        }));
        form.submit(retentionUpdate(project.uuid), { preserveScroll: true });
    };

    const capOf = (category: string) => {
        const row = retention.find((r) => r.category === category);

        return {
            days: row?.effective.cap_days ?? null,
            level: t(
                `settings.retention.levels.${row?.effective.cap_level ?? 'default'}`,
            ),
        };
    };
    const jobCap = capOf('job_runs');
    const logCap = capOf('logs');
    const cappedNote = (
        days: string,
        cap: { days: number | null; level: string },
    ) =>
        cap.days !== null && Number(days) > cap.days ? (
            <span className="text-xs text-warn">
                {t('settings.retention.override_capped', {
                    value: t('settings.days', { count: cap.days }, cap.days),
                    level: cap.level,
                })}
            </span>
        ) : null;
    const jobErrors = errorsFor(errors, 'job_overrides');
    const channelErrors = errorsFor(errors, 'channels');

    return (
        <Section
            id="retention"
            title={t('settings.retention.title')}
            description={t('settings.retention.description')}
        >
            <form
                className="flex flex-col gap-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    save();
                }}
            >
                <RetentionTable
                    level="project"
                    rows={retention}
                    stats={retention_stats}
                    data={{ days: form.data.days, forever: form.data.forever }}
                    setData={(next) => form.setData({ ...form.data, ...next })}
                    errors={errors}
                />

                <fieldset className="flex flex-col gap-2">
                    <legend className="mb-1.5 text-[13px] font-medium">
                        {t('settings.retention.job_overrides')}
                    </legend>
                    {form.data.job_overrides.length === 0 ? (
                        <p className="text-xs text-ink-3">
                            {t('settings.retention.no_job_overrides')}
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-1.5">
                            {form.data.job_overrides.map((override) => (
                                <li
                                    key={override.job_class}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-line px-3 py-2"
                                >
                                    <span className="min-w-0 font-mono text-xs break-all">
                                        {override.job_class}
                                    </span>
                                    {cappedNote(override.days, jobCap)}
                                    <span className="flex items-center gap-2">
                                        <span className="font-mono text-xs text-ink-2">
                                            {t(
                                                'settings.days',
                                                { count: override.days },
                                                Number(override.days),
                                            )}
                                        </span>
                                        <Button
                                            variant="ghost"
                                            onClick={() =>
                                                form.setData(
                                                    'job_overrides',
                                                    form.data.job_overrides.filter(
                                                        (o) =>
                                                            o.job_class !==
                                                            override.job_class,
                                                    ),
                                                )
                                            }
                                        >
                                            <X
                                                className="size-3.5"
                                                aria-hidden="true"
                                            />
                                            <span className="sr-only">
                                                {t(
                                                    'settings.retention.remove_override',
                                                    { job: override.job_class },
                                                )}
                                            </span>
                                        </Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="flex flex-wrap items-end gap-2">
                        <Field
                            label={t('settings.retention.job_class')}
                            htmlFor="override-class"
                            className="min-w-0 flex-[2_1_260px]"
                        >
                            <Input
                                id="override-class"
                                className="font-mono"
                                placeholder="App\Jobs\SendNewsletter"
                                value={jobClass}
                                onChange={(e) => setJobClass(e.target.value)}
                            />
                        </Field>
                        <Field
                            label={t('settings.retention.days_label')}
                            htmlFor="override-days"
                            className="w-28"
                        >
                            <Input
                                id="override-days"
                                type="number"
                                min={1}
                                inputMode="numeric"
                                className="font-mono"
                                value={jobDays}
                                onChange={(e) => setJobDays(e.target.value)}
                            />
                        </Field>
                        <Button
                            onClick={addOverride}
                            disabled={
                                jobClass.trim() === '' || jobDays.trim() === ''
                            }
                        >
                            {t('settings.retention.add_override')}
                        </Button>
                    </div>
                    {jobErrors.length > 0 && (
                        <p className="text-xs text-err">{jobErrors[0]}</p>
                    )}
                    <p className="text-xs text-ink-3">
                        {t('settings.retention.failed_jobs_hint')}
                    </p>
                </fieldset>

                {channels.length > 0 && (
                    <fieldset className="flex flex-col gap-2">
                        <legend className="mb-1.5 text-[13px] font-medium">
                            {t('settings.retention.channels')}
                        </legend>
                        <div className="grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-3">
                            {channels.map((channel) => (
                                <Field
                                    key={channel.id}
                                    label={
                                        <span className="font-mono text-xs">
                                            {channel.name}
                                        </span>
                                    }
                                    htmlFor={`retention-channel-${channel.id}`}
                                >
                                    <Input
                                        id={`retention-channel-${channel.id}`}
                                        type="number"
                                        min={1}
                                        inputMode="numeric"
                                        className="font-mono"
                                        placeholder={t(
                                            'settings.retention.inherit',
                                        )}
                                        value={form.data.channels[channel.id]}
                                        onChange={(e) =>
                                            form.setData('channels', {
                                                ...form.data.channels,
                                                [channel.id]: e.target.value,
                                            })
                                        }
                                    />
                                    {cappedNote(
                                        form.data.channels[channel.id] ?? '',
                                        logCap,
                                    )}
                                </Field>
                            ))}
                        </div>
                        {channelErrors.length > 0 && (
                            <p className="text-xs text-err">
                                {channelErrors[0]}
                            </p>
                        )}
                    </fieldset>
                )}

                <RetentionSaveBar
                    dirty={form.isDirty}
                    processing={form.processing}
                    onDiscard={() => form.reset()}
                />
            </form>
        </Section>
    );
}

function CopyBlock({
    label,
    hint,
    text,
}: {
    label: string;
    hint?: string;
    text: string;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <span className="text-[13px] font-medium">{label}</span>
            <CodeWithCopy text={text} />
            {hint && <span className="text-xs text-ink-3">{hint}</span>}
        </div>
    );
}

/** A code block with its Copy button in the corner, full width like every other. */
function CodeWithCopy({ text, tall }: { text: string; tall?: boolean }) {
    return (
        <div className="relative">
            <pre
                className={cn(
                    'overflow-auto rounded-md border border-line bg-ground py-3 pr-24 pl-3.5 font-mono text-xs leading-5 text-ink-2',
                    // One command wraps; a file keeps its lines and scrolls.
                    tall ? 'max-h-96' : 'break-all whitespace-pre-wrap',
                )}
            >
                {text}
            </pre>
            <CopyButton
                text={text}
                className="absolute top-2 right-2 bg-panel shadow-sm"
            />
        </div>
    );
}

function TriageProviderBlock({ provider }: { provider: TriageProvider }) {
    const t = useT();

    return (
        <section
            aria-labelledby={`triage-${provider.key}`}
            className="flex flex-col gap-3 rounded-lg border border-line p-4"
        >
            <h3 id={`triage-${provider.key}`} className="font-semibold">
                {provider.name}
            </h3>
            <ol className="flex list-decimal flex-col gap-1.5 pl-5 text-ink-2">
                <li>
                    {t('settings.triage.step_token')}{' '}
                    <a
                        href={teamShow().url}
                        className="text-amber hover:underline"
                    >
                        {t('settings.triage.tokens_link')}
                    </a>
                </li>
                <li>
                    {t('settings.triage.step_secrets')}{' '}
                    {provider.secrets.map((secret, i) => (
                        <span key={secret}>
                            {i > 0 && ', '}
                            <code className="font-mono text-xs text-ink">
                                {secret}
                            </code>
                        </span>
                    ))}
                </li>
                <li>
                    {t('settings.triage.step_workflow')}{' '}
                    <code className="font-mono text-xs text-ink">
                        {provider.path}
                    </code>
                </li>
            </ol>
            <CopyBlock
                label={t('settings.triage.local_title')}
                hint={t('settings.triage.local_hint', {
                    secret: provider.secrets[0] ?? '',
                })}
                text={provider.local}
            />
            <CodeWithCopy text={provider.workflow} tall />
        </section>
    );
}

function TriageSection({ triage }: Props) {
    const t = useT();

    return (
        <Section
            id="triage"
            title={t('settings.triage.title')}
            description={t('settings.triage.description')}
        >
            {triage.map((provider) => (
                <TriageProviderBlock key={provider.key} provider={provider} />
            ))}
            <p className="text-xs text-ink-3">{t('settings.triage.hint')}</p>
        </Section>
    );
}

function AlertsSection({ project, alerts }: Props) {
    const t = useT();

    return (
        <section
            id="section-alerts"
            aria-labelledby="h-alerts"
            className="flex scroll-mt-6 flex-col gap-4"
        >
            <h2 id="h-alerts" className="sr-only">
                {t('settings.alerts.title')}
            </h2>
            <ChannelsCard
                channels={alerts.channels}
                types={alerts.types}
                canAdminister={project.can_administer}
                project={project.uuid}
                description={t('settings.alerts.channels_description')}
            />
            <RulesCard
                scope="project"
                project={project.uuid}
                rules={alerts.rules}
                channels={alerts.available}
                canAdminister={project.can_administer}
            />
            {alerts.queue_thresholds.length > 0 && (
                <Card aria-labelledby="h-queue-thresholds">
                    <CardHeader
                        id="h-queue-thresholds"
                        title={t('settings.alerts.queues.title')}
                        description={t('settings.alerts.queues.description')}
                    />
                    <ul className="flex flex-col gap-1 px-4 pb-4 text-sm">
                        {alerts.queue_thresholds.map((q) => (
                            <li
                                key={`${q.connection}:${q.queue}`}
                                className="flex flex-wrap gap-2"
                            >
                                <span className="font-mono">
                                    {q.connection}:{q.queue}
                                </span>
                                <span className="text-ink-3">
                                    {q.muted
                                        ? t('settings.alerts.queues.muted')
                                        : [
                                              q.size !== null &&
                                                  t(
                                                      'settings.alerts.queues.size',
                                                      {
                                                          size: String(q.size),
                                                      },
                                                  ),
                                              q.oldest_minutes !== null &&
                                                  t(
                                                      'settings.alerts.queues.oldest',
                                                      {
                                                          minutes: String(
                                                              q.oldest_minutes,
                                                          ),
                                                      },
                                                  ),
                                              q.sustained_minutes !== null &&
                                                  t(
                                                      'settings.alerts.queues.sustained',
                                                      {
                                                          minutes: String(
                                                              q.sustained_minutes,
                                                          ),
                                                      },
                                                  ),
                                          ]
                                              .filter(Boolean)
                                              .join(', ')}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <div className="px-4 pb-4 text-sm">
                        <Link
                            href={jobsIndex(project.uuid).url}
                            className="text-accent underline"
                        >
                            {t('settings.alerts.queues.change')}
                        </Link>
                    </div>
                </Card>
            )}
        </section>
    );
}

function RepositorySection({ project, repository }: Props) {
    const t = useT();
    const initial = {
        provider: repository?.provider ?? 'github',
        repository: repository?.repository ?? '',
        token: '',
        token_expires_at: repository?.token_expires_at ?? '',
        labels: (repository?.labels ?? []).join(', '),
        allow_public: repository?.allow_public ?? false,
    };
    const form = useForm(initial);
    useFormSync(form, initial);

    // GitHub pre-fills a fine-grained token from these query parameters. The owner
    // (target_name) comes from the repository field; GitHub has no parameter for the
    // repository itself, so it still has to be picked under "Repository access".
    const owner = form.data.repository.includes('/')
        ? form.data.repository.split('/')[0]
        : '';
    const newTokenUrl = `https://github.com/settings/personal-access-tokens/new?${new URLSearchParams(
        {
            name: `Lumberjack ${form.data.repository.includes('/') ? form.data.repository : project.name}`.slice(
                0,
                40,
            ),
            description: t('settings.repository.token_description', {
                project: project.name,
                repository: form.data.repository || '-',
            }),
            ...(owner !== '' ? { target_name: owner } : {}),
            expires_in: '90',
            issues: 'write',
        },
    ).toString()}`;

    const expiresIn = form.data.token_expires_at
        ? Math.ceil(
              (new Date(`${form.data.token_expires_at}T23:59:59`).getTime() -
                  Date.now()) /
                  86_400_000,
          )
        : null;

    const save = () => {
        form.transform((data) => ({
            ...data,
            token: data.token === '' ? null : data.token,
            token_expires_at:
                data.token_expires_at === '' ? null : data.token_expires_at,
            labels: data.labels
                .split(',')
                .map((label) => label.trim())
                .filter((label) => label !== ''),
        }));
        form.submit(repositoryUpdate(project.uuid), {
            preserveScroll: true,
            onSuccess: () => form.setData('token', ''),
        });
    };

    return (
        <Section
            id="repository"
            title={t('settings.repository.title')}
            description={t('settings.repository.description')}
        >
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    save();
                }}
            >
                <Field
                    label={t('settings.repository.provider')}
                    htmlFor="repo-provider"
                    error={form.errors.provider}
                >
                    <Select
                        id="repo-provider"
                        value={form.data.provider}
                        onChange={(e) =>
                            form.setData('provider', e.target.value)
                        }
                    >
                        <option value="github">GitHub</option>
                    </Select>
                </Field>
                <Field
                    label={t('settings.repository.repository')}
                    htmlFor="repo-name"
                    hint={t('settings.repository.repository_hint')}
                    error={form.errors.repository}
                >
                    <Input
                        id="repo-name"
                        className="font-mono"
                        placeholder="owner/name"
                        value={form.data.repository}
                        onChange={(e) =>
                            form.setData('repository', e.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label={t('settings.repository.token')}
                    htmlFor="repo-token"
                    hint={
                        repository
                            ? t('settings.repository.token_keep_hint')
                            : t('settings.repository.token_hint')
                    }
                    error={form.errors.token}
                >
                    <div className="flex flex-wrap gap-2">
                        <Input
                            id="repo-token"
                            type="password"
                            className="min-w-0 flex-1 font-mono"
                            autoComplete="off"
                            placeholder={
                                repository ? '••••••••••••' : 'github_pat_'
                            }
                            value={form.data.token}
                            onChange={(e) =>
                                form.setData('token', e.target.value)
                            }
                            required={repository === null}
                        />
                        <a
                            href={newTokenUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className={buttonClass('default')}
                        >
                            <ExternalLink
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            {t('settings.repository.create_token')}
                            <span className="sr-only">
                                {t('settings.repository.opens_new_tab')}
                            </span>
                        </a>
                    </div>
                    <p className="text-xs text-ink-3">
                        {t('settings.repository.create_token_hint', {
                            repository: form.data.repository || 'owner/name',
                        })}
                    </p>
                </Field>
                <Field
                    label={t('settings.repository.expires')}
                    htmlFor="repo-expires"
                    error={form.errors.token_expires_at}
                >
                    <Input
                        id="repo-expires"
                        type="date"
                        className="w-44"
                        value={form.data.token_expires_at}
                        onChange={(e) =>
                            form.setData('token_expires_at', e.target.value)
                        }
                    />
                </Field>
                {expiresIn !== null && expiresIn <= 14 && (
                    <p
                        role="status"
                        className="rounded-md border border-warn-line bg-warn-bg px-3 py-2 text-xs text-warn"
                    >
                        {expiresIn < 0
                            ? t('settings.repository.expired')
                            : t(
                                  'settings.repository.expires_soon',
                                  { count: expiresIn },
                                  expiresIn,
                              )}
                    </p>
                )}
                <Field
                    label={t('settings.repository.labels')}
                    htmlFor="repo-labels"
                    hint={t('settings.repository.labels_hint')}
                    error={errorsFor(form.errors, 'labels')[0]}
                >
                    <Input
                        id="repo-labels"
                        className="font-mono"
                        placeholder="lumberjack, bug"
                        value={form.data.labels}
                        onChange={(e) => form.setData('labels', e.target.value)}
                    />
                </Field>
                <div className="flex items-center gap-2">
                    <Switch
                        id="repo-public"
                        small
                        checked={form.data.allow_public}
                        onChange={(on) => form.setData('allow_public', on)}
                        label={t('settings.repository.allow_public')}
                    />
                    <Label htmlFor="repo-public">
                        {t('settings.repository.allow_public')}
                    </Label>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </Button>
                    {repository && (
                        <>
                            <Button
                                onClick={() =>
                                    router.post(
                                        repositoryTest(project.uuid).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('settings.repository.test')}
                            </Button>
                            <ConfirmButton
                                question={t(
                                    'settings.repository.unlink_question',
                                )}
                                confirmLabel={t('settings.repository.unlink')}
                                onConfirm={() =>
                                    router.delete(
                                        repositoryDestroy(project.uuid).url,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('settings.repository.unlink')}
                            </ConfirmButton>
                        </>
                    )}
                </div>
            </form>
        </Section>
    );
}

function DangerSection({ project }: Props) {
    const t = useT();
    const [confirming, setConfirming] = useState(false);
    const [typed, setTyped] = useState('');
    const [processing, setProcessing] = useState(false);

    return (
        <Section id="danger" title={t('settings.danger.title')} danger>
            <p className="text-ink-2">{t('settings.danger.description')}</p>
            {!confirming ? (
                <div>
                    <Button
                        variant="danger"
                        onClick={() => setConfirming(true)}
                    >
                        {t('settings.danger.delete')}
                    </Button>
                </div>
            ) : (
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();

                        if (typed === project.name) {
                            router.delete(projectDestroy(project.uuid).url, {
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                            });
                        }
                    }}
                >
                    <Field
                        label={t('settings.danger.type_name', {
                            name: project.name,
                        })}
                        htmlFor="delete-confirm"
                    >
                        <Input
                            id="delete-confirm"
                            autoFocus
                            autoComplete="off"
                            className="font-mono"
                            value={typed}
                            onChange={(e) => setTyped(e.target.value)}
                        />
                    </Field>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            variant="danger"
                            disabled={typed !== project.name || processing}
                        >
                            {t('settings.danger.confirm')}
                        </Button>
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setConfirming(false);
                                setTyped('');
                            }}
                        >
                            {t('common.cancel')}
                        </Button>
                    </div>
                </form>
            )}
        </Section>
    );
}
