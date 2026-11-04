import { WithRelative } from '@/components/lj/relative';
import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    bucketLabel,
    Button,
    Card,
    EmptyState,
    Eyebrow,
    Field,
    Input,
    PageTitle,
    Sparkline,
} from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatDuration, formatNumber, formatPercent } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { useLiveChannel, useTeamLive } from '@/lib/use-live';
import type { LiveReload } from '@/lib/use-live';
import { show as projectShow, store as projectStore } from '@/routes/projects';
import type { SeriesPoint, TeamShared } from '@/types/lumberjack';

type ProjectCard = {
    uuid: string;
    slug: string;
    name: string;
    open_issues: number;
    errors: number;
    p95: number | null;
    job_failure_rate: number | null;
    error_series: SeriesPoint[];
    agents: number;
    offline_agents: string[];
    last_agent_seen_at: string | null;
};

/**
 * A card's figures change with its project's issues, jobs and agents. The day's figures
 * are reused for a minute on the server, so they may trail by that much.
 */
function ProjectLive({ uuid, live }: { uuid: string; live: LiveReload }) {
    useLiveChannel(
        `project.${uuid}`,
        ['issues.changed', 'jobs.changed', 'agents.changed'],
        live,
    );

    return null;
}

export default function ProjectsIndex({
    projects,
}: {
    projects: ProjectCard[];
}) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [search, setSearch] = useState('');
    const live = useTeamLive(['projects.changed'], ['projects']);
    const [creating, setCreating] = useState(false);
    // The prefix follows the name until it is edited by hand.
    const [prefix, setPrefix] = useState('');
    const [prefixEdited, setPrefixEdited] = useState(false);
    // Several words give their initials (Billing API: BA), one word itself (Demo: DEMO).
    const suggestPrefix = (name: string) => {
        const words = name
            .toUpperCase()
            .split(/[^A-Z0-9]+/)
            .filter(Boolean);
        const letters =
            words.length > 1
                ? words.map((word) => word[0]).join('')
                : (words[0] ?? '');
        return (/^[A-Z]/.test(letters) ? letters : `P${letters}`).slice(0, 8);
    };

    const visible = useMemo(
        () =>
            projects.filter((p) =>
                p.name.toLowerCase().includes(search.toLowerCase()),
            ),
        [projects, search],
    );

    return (
        <>
            {projects.map((project) => (
                <ProjectLive
                    key={project.uuid}
                    uuid={project.uuid}
                    live={live}
                />
            ))}
            <Head title={t('projects.title')} />
            <TopBar
                crumbs={[{ label: team.name }, { label: t('projects.title') }]}
            />
            <PageBody wide>
                <PageTitle
                    title={t('projects.title')}
                    description={t(
                        'projects.description',
                        { team: team.name },
                        projects.length,
                    )}
                    actions={
                        <>
                            <label className="sr-only" htmlFor="project-search">
                                {t('projects.search')}
                            </label>
                            <Input
                                id="project-search"
                                type="search"
                                placeholder={t('projects.search')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-56"
                            />
                            {team.can_administer && (
                                <Button
                                    variant="primary"
                                    onClick={() => setCreating(true)}
                                >
                                    <Plus
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {t('projects.new')}
                                </Button>
                            )}
                        </>
                    }
                />

                {projects.length === 0 ? (
                    <Card>
                        <EmptyState title={t('projects.empty_title')}>
                            {t('projects.empty_body')}
                        </EmptyState>
                    </Card>
                ) : (
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(320px,1fr))] gap-4">
                        {visible.map((project) => {
                            const offline = project.offline_agents.length > 0;

                            return (
                                <Link
                                    key={project.uuid}
                                    href={projectShow(project.uuid).url}
                                    className="flex flex-col gap-4 rounded-lg border border-line bg-panel p-[18px] hover:border-line-strong"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <span className="font-mono text-[15px] font-medium">
                                                {project.name}
                                            </span>
                                            <span className="text-xs text-ink-3">
                                                {project.slug}
                                            </span>
                                        </div>
                                        {/* The open issues are in the figures below; the badge
                                            says what they do not: agents, or all calm. */}
                                        {offline ? (
                                            <Badge tone="warn">
                                                {t(
                                                    'projects.agent_offline',
                                                    {},
                                                    project.offline_agents
                                                        .length,
                                                )}
                                            </Badge>
                                        ) : (
                                            project.open_issues === 0 && (
                                                <Badge tone="ok">
                                                    {t('projects.status_calm')}
                                                </Badge>
                                            )
                                        )}
                                    </div>
                                    <dl className="grid grid-cols-[repeat(4,auto)] justify-between gap-x-4 gap-y-3">
                                        {[
                                            {
                                                label: t(
                                                    'projects.open_issues',
                                                ),
                                                value: formatNumber(
                                                    project.open_issues,
                                                ),
                                                tone:
                                                    project.open_issues > 0
                                                        ? 'text-err'
                                                        : '',
                                            },
                                            {
                                                label: t('projects.errors'),
                                                value: formatNumber(
                                                    project.errors,
                                                ),
                                                tone: '',
                                            },
                                            {
                                                label: t('projects.p95'),
                                                value:
                                                    project.p95 === null
                                                        ? '-'
                                                        : formatDuration(
                                                              project.p95,
                                                          ),
                                                tone: '',
                                            },
                                            {
                                                label: t(
                                                    'projects.job_failures',
                                                ),
                                                value:
                                                    project.job_failure_rate ===
                                                    null
                                                        ? '-'
                                                        : formatPercent(
                                                              project.job_failure_rate,
                                                          ),
                                                tone: '',
                                            },
                                        ].map((metric) => (
                                            <div
                                                key={metric.label}
                                                className="flex flex-col gap-1"
                                            >
                                                <dt className="whitespace-nowrap">
                                                    <Eyebrow>
                                                        {metric.label}
                                                    </Eyebrow>
                                                </dt>
                                                <dd
                                                    className={`font-mono text-lg whitespace-nowrap ${metric.tone}`}
                                                >
                                                    {metric.value}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                    <div className="flex flex-col gap-1.5">
                                        <Sparkline
                                            values={project.error_series.map(
                                                (p) => p.v,
                                            )}
                                            label={t('projects.spark_label', {
                                                count: project.errors,
                                            })}
                                            tips={project.error_series.map(
                                                (p) =>
                                                    `${bucketLabel(p.t, '24h')} · ${formatNumber(p.v)}`,
                                            )}
                                            width={300}
                                            height={40}
                                        />
                                        <div className="flex justify-between gap-2 text-[11px] text-ink-3">
                                            <span className="whitespace-nowrap">
                                                {t('projects.errors_per_hour')}
                                            </span>
                                            {!offline && (
                                                <span className="truncate">
                                                    <WithRelative
                                                        iso={
                                                            project.last_agent_seen_at
                                                        }
                                                    >
                                                        {(time) =>
                                                            t(
                                                                'projects.agents_seen',
                                                                { time },
                                                                project.agents,
                                                            )
                                                        }
                                                    </WithRelative>
                                                </span>
                                            )}
                                        </div>
                                        {offline && (
                                            // One line; the whole list on hover.
                                            <span
                                                className="truncate text-[11px] text-warn"
                                                title={project.offline_agents.join(
                                                    ', ',
                                                )}
                                            >
                                                {t('projects.agent_missing', {
                                                    hosts: project.offline_agents.join(
                                                        ', ',
                                                    ),
                                                })}
                                            </span>
                                        )}
                                    </div>
                                </Link>
                            );
                        })}
                    </div>
                )}
            </PageBody>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <Form
                        {...projectStore.form()}
                        onSuccess={() => {
                            setCreating(false);
                            setPrefix('');
                            setPrefixEdited(false);
                        }}
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>
                                        {t('projects.new')}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {t('projects.create_hint')}
                                    </DialogDescription>
                                </DialogHeader>
                                <Field
                                    label={t('projects.name')}
                                    htmlFor="project-name"
                                    error={errors.name}
                                >
                                    <Input
                                        id="project-name"
                                        name="name"
                                        required
                                        autoFocus
                                        onChange={(e) => {
                                            if (!prefixEdited) {
                                                setPrefix(
                                                    suggestPrefix(
                                                        e.target.value,
                                                    ),
                                                );
                                            }
                                        }}
                                    />
                                </Field>
                                <Field
                                    label={t('projects.issue_prefix')}
                                    htmlFor="project-issue-prefix"
                                    hint={t('projects.issue_prefix_hint', {
                                        example: `${prefix || 'DEMO'}-42`,
                                    })}
                                    error={errors.issue_prefix}
                                >
                                    <Input
                                        id="project-issue-prefix"
                                        name="issue_prefix"
                                        className="w-40 font-mono uppercase"
                                        value={prefix}
                                        onChange={(e) => {
                                            setPrefixEdited(true);
                                            setPrefix(
                                                e.target.value
                                                    .toUpperCase()
                                                    .replace(/[^A-Z0-9]/g, ''),
                                            );
                                        }}
                                        required
                                        minLength={2}
                                        maxLength={8}
                                        spellCheck={false}
                                    />
                                </Field>
                                <DialogFooter>
                                    <Button onClick={() => setCreating(false)}>
                                        {t('common.cancel')}
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        disabled={processing}
                                    >
                                        {t('projects.create')}
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
