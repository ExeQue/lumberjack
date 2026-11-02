import { Head, Link, usePage } from '@inertiajs/react';
import { ChannelsCard } from '@/components/lj/alerts';
import type { Channel } from '@/components/lj/alerts';
import { TopBar } from '@/components/lj/top-bar';
import { Card, CardHeader, PageTitle } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import { settings as projectSettings } from '@/routes/projects';
import type { TeamShared } from '@/types/lumberjack';

type Props = {
    channels: Channel[];
    types: string[];
    projects: { uuid: string; name: string }[];
    can_administer: boolean;
};

/**
 * The team's shared alert channels. Each project sets its rules, and adds channels
 * of its own such as a webhook, in its settings.
 */
export default function Alerts({
    channels,
    types,
    projects,
    can_administer,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;

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
        </>
    );
}
