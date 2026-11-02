import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ChannelsCard, RulesCard } from '@/components/lj/alerts';
import type {
    Channel as AlertChannel,
    Rule as AlertRule,
} from '@/components/lj/alerts';
import { ConfirmButton } from '@/components/lj/confirm-button';
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
    tableClass,
    tdClass,
    thClass,
    numClass,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatNumber, formatRelative } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    destroy as channelDestroy,
    store as channelStore,
} from '@/routes/log-channels';
import {
    destroy as projectDestroy,
    retention as retentionUpdate,
    rotatePublicKey,
    rotateToken,
    show as projectShow,
} from '@/routes/projects';
import {
    destroy as labelDestroy,
    update as labelUpdate,
} from '@/routes/labels';
import { update as settingsUpdate } from '@/routes/projects/settings';
import { show as teamShow } from '@/routes/team';
import {
    destroy as repositoryDestroy,
    test as repositoryTest,
    update as repositoryUpdate,
} from '@/routes/repository';
import type { ProjectHeader, TeamShared } from '@/types/lumberjack';

type Settings = {
    name: string;
    slug: string;
    allowed_origins: string[];
    scrub_keys: string[];
    default_scrub_keys: string[];
    token_last_four: string | null;
    public_key: string;
    ingest_url: string;
    browser_sdk_url: string;
    server_url: string;
};

type Agent = {
    host: string;
    name: string | null;
    version: string | null;
    last_seen_at: string;
    online: boolean;
};
type Channel = {
    id: number;
    name: string;
    is_default: boolean;
    lines_24h: number;
    retention_days: number | null;
};
type RetentionRow = {
    category: 'errors' | 'jobs' | 'logs' | 'performance';
    label: string;
    team_days: number;
    project_days: number | null;
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
    channels: Channel[];
    labels: (IssueLabel & { issues: number })[];
    unknown_channels: string[];
    retention: RetentionRow[];
    job_overrides: JobOverride[];
    repository: Repository;
    triage: TriageProvider[];
    alerts: {
        channels: AlertChannel[];
        available: AlertChannel[];
        types: string[];
        rules: AlertRule[];
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

const STORED_SECTION = 'lumberjack.settings.section';

const isSection = (value: string | null): value is SectionKey =>
    value !== null && (SECTIONS as readonly string[]).includes(value);

/**
 * The open section: from the address, else the one open before a save
 * redirected back without it, else the first.
 */
const sectionFromHash = (): SectionKey => {
    if (typeof window === 'undefined') {
        return 'general';
    }

    const hash = window.location.hash.slice(1);

    if (isSection(hash)) {
        return hash;
    }

    try {
        const stored = window.sessionStorage.getItem(STORED_SECTION);

        return isSection(stored) ? stored : 'general';
    } catch {
        return 'general';
    }
};

export default function ProjectSettings(props: Props) {
    const t = useT();
    const [active, setActive] = useState<SectionKey>(sectionFromHash);

    // Back and forward between sections, and links to #section on this page.
    useEffect(() => {
        const onHashChange = () => setActive(sectionFromHash());
        window.addEventListener('hashchange', onHashChange);

        return () => window.removeEventListener('hashchange', onHashChange);
    }, []);

    // Keep the open section in the address and for this tab, so a save that
    // redirects back opens it again.
    useEffect(() => {
        try {
            window.sessionStorage.setItem(STORED_SECTION, active);
        } catch {
            // Storage can be refused; the address still carries the section.
        }

        if (window.location.hash.slice(1) !== active) {
            window.history.replaceState(window.history.state, '', `#${active}`);
        }
    }, [active]);
    const { team } = usePage<{ team: TeamShared }>().props;
    const { project } = props;

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
                        className="flex flex-wrap gap-1 lg:sticky lg:top-6 lg:flex-col"
                    >
                        {SECTIONS.map((section) => (
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
                                    'rounded-md px-2.5 py-1.5 text-ink-2 hover:bg-panel-2 hover:text-ink',
                                    active === section &&
                                        'bg-panel-2 font-medium text-ink shadow-[inset_2px_0_0_var(--lj-amber)]',
                                    section === 'danger' && 'text-err',
                                )}
                            >
                                {t(`settings.${section}.title`)}
                            </a>
                        ))}
                    </nav>
                    {/* One section at a time; the address keeps which, so a link can open it. */}
                    <div className="flex min-w-0 flex-col gap-5">
                        {active === 'general' && <GeneralSection {...props} />}
                        {active === 'install' && <InstallSection {...props} />}
                        {active === 'keys' && <KeysSection {...props} />}
                        {active === 'agents' && <AgentsSection {...props} />}
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
        </>
    );
}

function GeneralSection({ project, settings }: Props) {
    const t = useT();
    const form = useForm({
        name: settings.name,
        slug: settings.slug,
        allowed_origins: settings.allowed_origins,
        scrub_keys: settings.scrub_keys,
    });
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
                composer require exeque/lumberjack-laravel
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
    const [copied, setCopied] = useState(false);

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
                    <Button
                        onClick={() => {
                            void navigator.clipboard.writeText(
                                settings.public_key,
                            );
                            setCopied(true);
                        }}
                    >
                        {copied ? t('common.copied') : t('common.copy')}
                    </Button>
                    <ConfirmButton
                        variant="default"
                        question={t('settings.keys.rotate_public_question')}
                        confirmLabel={t('settings.keys.rotate')}
                        onConfirm={() =>
                            router.post(
                                rotatePublicKey(project.uuid).url,
                                {},
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setCopied(false),
                                },
                            )
                        }
                    >
                        {t('settings.keys.rotate')}
                    </ConfirmButton>
                </div>
            </Field>
        </Section>
    );
}

function AgentsSection({ agents }: Props) {
    const t = useT();

    return (
        <Section
            id="agents"
            title={t('settings.agents.title')}
            description={t('settings.agents.description')}
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
                                    {t('settings.agents.version')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.agents.last_seen')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.agents.status')}
                                </th>
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
                                        {agent.version ?? '-'}
                                    </td>
                                    <td className={tdClass}>
                                        {formatRelative(agent.last_seen_at)}
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
    const form = useForm({ name: label.name, color: label.color });

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
    const projectLogDays = logs?.project_days ?? logs?.team_days ?? 0;
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
    job_overrides,
    channels,
}: Props) {
    const t = useT();
    const form = useForm({
        days: Object.fromEntries(
            retention.map((r) => [
                r.category,
                r.project_days?.toString() ?? '',
            ]),
        ) as Record<string, string>,
        job_overrides: job_overrides.map((o) => ({
            job_class: o.job_class,
            days: o.days.toString(),
        })),
        channels: Object.fromEntries(
            channels.map((c) => [c.id, c.retention_days?.toString() ?? '']),
        ) as Record<string, string>,
    });
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
            days: Object.fromEntries(
                Object.entries(data.days).map(([key, value]) => [
                    key,
                    daysOrNull(value),
                ]),
            ),
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
                <div className="overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                <th className={thClass}>
                                    {t('settings.retention.category')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.retention.team_value')}
                                </th>
                                <th className={thClass}>
                                    {t('settings.retention.project_value')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {retention.map((row) => (
                                <tr key={row.category}>
                                    <td className={tdClass}>{row.label}</td>
                                    <td className={cn(tdClass, 'text-ink-2')}>
                                        {t(
                                            'settings.days',
                                            { count: row.team_days },
                                            row.team_days,
                                        )}
                                    </td>
                                    <td className={tdClass}>
                                        <label
                                            className="sr-only"
                                            htmlFor={`retention-${row.category}`}
                                        >
                                            {t('settings.retention.days_for', {
                                                label: row.label,
                                            })}
                                        </label>
                                        <Input
                                            id={`retention-${row.category}`}
                                            type="number"
                                            min={1}
                                            inputMode="numeric"
                                            className="w-28 font-mono"
                                            placeholder={t(
                                                'settings.retention.inherit',
                                            )}
                                            value={form.data.days[row.category]}
                                            onChange={(e) =>
                                                form.setData('days', {
                                                    ...form.data.days,
                                                    [row.category]:
                                                        e.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                errors[
                                                    `days.${row.category}`
                                                ] !== undefined
                                            }
                                        />
                                        {errors[`days.${row.category}`] && (
                                            <p className="mt-1 text-xs text-err">
                                                {errors[`days.${row.category}`]}
                                            </p>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

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

function CopyBlock({
    label,
    hint,
    text,
}: {
    label: string;
    hint?: string;
    text: string;
}) {
    const t = useT();
    const [copied, setCopied] = useState(false);

    return (
        <div className="flex flex-col gap-1.5">
            <span className="text-[13px] font-medium">{label}</span>
            <div className="flex items-start gap-2">
                <pre className="min-w-0 flex-1 overflow-x-auto rounded-md border border-line bg-ground px-3.5 py-3 font-mono text-xs leading-5 text-ink-2">
                    {text}
                </pre>
                <Button
                    onClick={() => {
                        void navigator.clipboard.writeText(text);
                        setCopied(true);
                        window.setTimeout(() => setCopied(false), 2000);
                    }}
                >
                    {copied ? t('common.copied') : t('common.copy')}
                </Button>
            </div>
            {hint && <span className="text-xs text-ink-3">{hint}</span>}
        </div>
    );
}

function TriageProviderBlock({ provider }: { provider: TriageProvider }) {
    const t = useT();
    const [copied, setCopied] = useState(false);

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
            <div className="relative">
                <pre className="max-h-96 overflow-auto rounded-md border border-line bg-ground px-3.5 py-3 font-mono text-xs leading-5 text-ink-2">
                    {provider.workflow}
                </pre>
                <Button
                    className="absolute top-2 right-2"
                    onClick={() => {
                        void navigator.clipboard.writeText(provider.workflow);
                        setCopied(true);
                        window.setTimeout(() => setCopied(false), 2000);
                    }}
                >
                    {copied ? t('common.copied') : t('common.copy')}
                </Button>
            </div>
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
                rules={alerts.rules}
                channels={alerts.available}
                canAdminister={project.can_administer}
            />
        </section>
    );
}

function RepositorySection({ project, repository }: Props) {
    const t = useT();
    const form = useForm({
        provider: repository?.provider ?? 'github',
        repository: repository?.repository ?? '',
        token: '',
        token_expires_at: repository?.token_expires_at ?? '',
        labels: (repository?.labels ?? []).join(', '),
        allow_public: repository?.allow_public ?? false,
    });

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
                    <input
                        id="repo-public"
                        type="checkbox"
                        className="size-4 accent-amber"
                        checked={form.data.allow_public}
                        onChange={(e) =>
                            form.setData('allow_public', e.target.checked)
                        }
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
