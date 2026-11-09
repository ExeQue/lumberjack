import { Head, Link, usePage } from '@inertiajs/react';
import { ChannelsCard, RulesCard } from '@/components/lj/alerts';
import type { Channel } from '@/components/lj/alerts';
import { SentAlertsCard, TopicsCard } from '@/components/lj/custom-alerts';
import type { AlertTopic, SentAlert } from '@/components/lj/custom-alerts';
import { LiveNotice } from '@/components/lj/live-notice';
import { TopBar } from '@/components/lj/top-bar';
import { Card, CardHeader, PageTitle } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import { useTeamLive } from '@/lib/use-live';
import { settings as projectSettings } from '@/routes/projects';
import type { AlertRule, TeamShared } from '@/types/lumberjack';

type Props = {
    channels: Channel[];
    types: string[];
    projects: { uuid: string; name: string }[];
    rules: AlertRule[];
    topics: AlertTopic[];
    sent_alerts: SentAlert[];
    can_administer: boolean;
};

/**
 * The team's shared alert channels and rules. Each project uses the rules unless it
 * customizes one, and adds channels of its own such as a webhook, in its settings.
 */
export default function Alerts({
    channels,
    types,
    projects,
    rules,
    topics,
    sent_alerts,
    can_administer,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const live = useTeamLive(
        ['alerts.changed', 'projects.changed'],
        ['channels', 'rules', 'projects', 'topics', 'sent_alerts'],
        { guard: true },
    );

    return (
        <>
            <Head title={t('alerts.title')} />
            <TopBar
                crumbs={[{ label: team.name }, { label: t('alerts.title') }]}
            />
            <PageBody>
                <PageTitle title={t('alerts.title')} />

                <ChannelsCard
                    channels={channels}
                    types={types}
                    canAdminister={can_administer}
                />

                <RulesCard
                    scope="team"
                    rules={rules}
                    channels={channels}
                    canAdminister={can_administer}
                />

                <TopicsCard
                    topics={topics}
                    channels={channels}
                    canAdminister={can_administer}
                />

                <SentAlertsCard alerts={sent_alerts} />

                <Card aria-labelledby="h-per-project">
                    <CardHeader
                        id="h-per-project"
                        title={t('alerts.per_project.title')}
                        description={t('alerts.per_project.description')}
                    />
                    <ul className="flex flex-col gap-1.5 p-4">
                        {projects.map((project) => (
                            <li key={project.uuid}>
                                <Link
                                    href={`${projectSettings(project.uuid).url}#alerts`}
                                    className="text-amber hover:underline"
                                >
                                    {project.name}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            </PageBody>
            <LiveNotice live={live} />
        </>
    );
}
