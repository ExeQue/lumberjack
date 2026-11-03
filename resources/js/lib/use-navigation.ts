import { usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import { audit as adminAudit } from '@/routes/admin';
import { index as adminTeams } from '@/routes/admin/teams';
import { index as adminUsers } from '@/routes/admin/users';
import { index as alertsIndex } from '@/routes/alerts';
import { index as commandsIndex } from '@/routes/commands';
import { index as healthIndex } from '@/routes/health';
import { index as issuesIndex } from '@/routes/issues';
import { index as jobsIndex } from '@/routes/jobs';
import { index as logsIndex } from '@/routes/logs';
import {
    index as projectsIndex,
    settings as projectSettings,
    show as projectShow,
} from '@/routes/projects';
import { index as queriesIndex } from '@/routes/queries';
import { index as releasesIndex } from '@/routes/releases';
import { index as routesIndex } from '@/routes/routes';
import { index as scheduleIndex } from '@/routes/schedule';
import { show as teamShow } from '@/routes/team';
import type { ProjectHeader } from '@/types/lumberjack';

export type NavItem = {
    label: string;
    href: string;
    active: boolean;
    count?: number;
};

/** The pages the sidebar and the command palette offer, in sidebar order. */
export function useNavigation(
    project: Pick<ProjectHeader, 'uuid' | 'can_administer'> | undefined,
    counts:
        | {
              issues: number;
              jobs: number;
              schedule: number;
              health: number;
              commands: number;
          }
        | undefined,
    is_admin: boolean,
): { projectNav: NavItem[]; teamNav: NavItem[]; adminNav: NavItem[] } {
    const t = useT();
    const page = usePage();
    const path = page.url.split('?')[0];
    const isAt = (href: string, exact = false) =>
        exact ? path === href : path === href || path.startsWith(href + '/');

    const projectNav: NavItem[] = project
        ? [
              {
                  label: t('common.nav.overview'),
                  href: projectShow(project.uuid).url,
                  active: isAt(projectShow(project.uuid).url, true),
              },
              {
                  label: t('common.nav.issues'),
                  href: issuesIndex(project.uuid).url,
                  active: isAt(issuesIndex(project.uuid).url),
                  count: counts?.issues,
              },
              {
                  label: t('common.nav.routes'),
                  href: routesIndex(project.uuid).url,
                  active: isAt(routesIndex(project.uuid).url),
              },
              {
                  label: t('common.nav.queries'),
                  href: queriesIndex(project.uuid).url,
                  active: isAt(queriesIndex(project.uuid).url),
              },
              {
                  label: t('common.nav.releases'),
                  href: releasesIndex(project.uuid).url,
                  active: isAt(releasesIndex(project.uuid).url),
              },
              {
                  label: t('common.nav.jobs'),
                  href: jobsIndex(project.uuid).url,
                  active: isAt(jobsIndex(project.uuid).url),
                  count: counts?.jobs,
              },
              {
                  label: t('common.nav.schedule'),
                  href: scheduleIndex(project.uuid).url,
                  active: isAt(scheduleIndex(project.uuid).url),
                  count: counts?.schedule,
              },
              {
                  label: t('common.nav.commands'),
                  href: commandsIndex(project.uuid).url,
                  active: isAt(commandsIndex(project.uuid).url),
                  count: counts?.commands,
              },
              {
                  label: t('common.nav.logs'),
                  href: logsIndex(project.uuid).url,
                  active: isAt(logsIndex(project.uuid).url),
              },
              {
                  label: t('common.nav.health'),
                  href: healthIndex(project.uuid).url,
                  active: isAt(healthIndex(project.uuid).url),
                  count: counts?.health,
              },
              ...(project.can_administer
                  ? [
                        {
                            label: t('common.nav.settings'),
                            href: projectSettings(project.uuid).url,
                            active: isAt(projectSettings(project.uuid).url),
                        },
                    ]
                  : []),
          ]
        : [];

    const teamNav: NavItem[] = [
        {
            label: t('common.nav.all_projects'),
            href: projectsIndex().url,
            active: isAt(projectsIndex().url, true),
        },
        {
            label: t('common.nav.team'),
            href: teamShow().url,
            active: isAt(teamShow().url, true),
        },
        {
            label: t('common.nav.alerts'),
            href: alertsIndex().url,
            active: isAt(alertsIndex().url),
        },
    ];

    const adminNav: NavItem[] = is_admin
        ? [
              {
                  label: t('admin.nav.teams'),
                  href: adminTeams().url,
                  active: isAt(adminTeams().url),
              },
              {
                  label: t('admin.nav.users'),
                  href: adminUsers().url,
                  active: isAt(adminUsers().url),
              },
              {
                  label: t('admin.nav.audit'),
                  href: adminAudit().url,
                  active: isAt(adminAudit().url),
              },
          ]
        : [];

    return { projectNav, teamNav, adminNav };
}

const sections: Record<string, (project: string) => { url: string }> = {
    issues: issuesIndex,
    routes: routesIndex,
    queries: queriesIndex,
    releases: releasesIndex,
    jobs: jobsIndex,
    schedule: scheduleIndex,
    commands: commandsIndex,
    logs: logsIndex,
    health: healthIndex,
    settings: projectSettings,
};

/**
 * Where switching to another project lands: the same section as the page you are on
 * (an issue opens that project's issues), else its overview.
 */
export function useProjectSwitchUrl(): (project: string) => string {
    const page = usePage<{ team: { can_administer: boolean } | null }>();
    const section = page.url.match(/^\/projects\/[^/?#]+\/([a-z-]+)/)?.[1];
    const to =
        section !== undefined &&
        section in sections &&
        (section !== 'settings' || page.props.team?.can_administer)
            ? sections[section]
            : null;

    return (project) => (to ?? projectShow)(project).url;
}
