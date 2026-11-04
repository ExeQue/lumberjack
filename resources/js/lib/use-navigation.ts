import { usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import {
    audit as adminAudit,
    retention as adminRetention,
} from '@/routes/admin';
import { index as adminTeams } from '@/routes/admin/teams';
import { index as adminUsers } from '@/routes/admin/users';
import { index as alertsIndex } from '@/routes/alerts';
import { index as commandsIndex } from '@/routes/commands';
import { index as dataSourcesIndex } from '@/routes/data-sources';
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
import { index as screensIndex } from '@/routes/screens';
import { displays as teamDisplays, show as teamShow } from '@/routes/team';
import { show as teamRetention } from '@/routes/team/retention';
import type { ProjectHeader } from '@/types/lumberjack';

export type NavItem = {
    label: string;
    href: string;
    active: boolean;
    count?: number;
    /** Not an Inertia page (Horizon's own dashboard): a plain link that leaves the app. */
    external?: boolean;
    /** Related pages behind this one entry, shown as tabs on each of them. */
    tabs?: NavItem[];
    /** Reached another way than the sidebar list (project settings: the gear). */
    hidden?: boolean;
};

/** A group of pages behind one sidebar entry: active on any of them, their counts added. */
function group(label: string, tabs: NavItem[]): NavItem {
    const counts = tabs.map((tab) => tab.count ?? 0);

    return {
        label,
        href: tabs[0].href,
        active: tabs.some((tab) => tab.active),
        count: counts.reduce((sum, n) => sum + n, 0),
        tabs,
    };
}

/** Every page behind the entries, one per page: for the command palette. */
export function leaves(items: NavItem[]): NavItem[] {
    return items.flatMap((item) => item.tabs ?? [item]);
}

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
    const item = (
        label: string,
        href: string,
        extra: Partial<NavItem> & { exact?: boolean } = {},
    ): NavItem => {
        const { exact = false, ...rest } = extra;

        return { label, href, active: isAt(href, exact), ...rest };
    };

    const projectNav: NavItem[] = project
        ? [
              item(t('common.nav.overview'), projectShow(project.uuid).url, {
                  exact: true,
              }),
              item(t('common.nav.issues'), issuesIndex(project.uuid).url, {
                  count: counts?.issues,
              }),
              item(t('common.nav.routes'), routesIndex(project.uuid).url),
              item(t('common.nav.queries'), queriesIndex(project.uuid).url),
              group(t('common.nav.console'), [
                  item(t('common.nav.jobs'), jobsIndex(project.uuid).url, {
                      count: counts?.jobs,
                  }),
                  item(
                      t('common.nav.schedule'),
                      scheduleIndex(project.uuid).url,
                      {
                          count: counts?.schedule,
                      },
                  ),
                  item(
                      t('common.nav.commands'),
                      commandsIndex(project.uuid).url,
                      {
                          count: counts?.commands,
                      },
                  ),
              ]),
              item(t('common.nav.logs'), logsIndex(project.uuid).url),
              item(t('common.nav.health'), healthIndex(project.uuid).url, {
                  count: counts?.health,
              }),
              item(t('common.nav.releases'), releasesIndex(project.uuid).url),
              // The gear: the settings (with the data sources listed in their menu) for
              // whoever administers the project, else the data sources, read only.
              {
                  ...item(
                      t('common.nav.project_settings'),
                      project.can_administer
                          ? projectSettings(project.uuid).url
                          : dataSourcesIndex(project.uuid).url,
                  ),
                  active:
                      isAt(projectSettings(project.uuid).url) ||
                      isAt(dataSourcesIndex(project.uuid).url),
                  hidden: true,
              },
              // Not in the sidebar's list, but in the command palette.
              item(
                  t('common.nav.data_sources'),
                  dataSourcesIndex(project.uuid).url,
                  { hidden: true },
              ),
          ]
        : [];

    const teamNav: NavItem[] = [
        item(t('common.nav.projects'), projectsIndex().url, { exact: true }),
        group(t('common.nav.screens'), [
            item(t('common.nav.screens'), screensIndex().url),
            item(t('common.nav.displays'), teamDisplays().url),
        ]),
        item(t('common.nav.alerts'), alertsIndex().url),
        group(t('common.nav.team_page'), [
            item(t('common.nav.team'), teamShow().url, { exact: true }),
            item(t('common.nav.retention'), teamRetention().url),
        ]),
    ];

    const adminNav: NavItem[] = is_admin
        ? [
              item(t('admin.nav.teams'), adminTeams().url),
              item(t('admin.nav.users'), adminUsers().url),
              item(t('admin.nav.audit'), adminAudit().url),
              item(t('admin.nav.retention'), adminRetention().url),
              {
                  label: t('admin.nav.horizon'),
                  href: '/horizon',
                  active: false,
                  external: true,
              },
          ]
        : [];

    return { projectNav, teamNav, adminNav };
}

/** The tabs of the group the page belongs to, or null on a page of its own. */
export function useSectionTabs(): NavItem[] | null {
    const page = usePage<{
        project?: Pick<ProjectHeader, 'uuid' | 'can_administer'>;
        sidebar_project?: Pick<ProjectHeader, 'uuid' | 'can_administer'> & {
            counts?: Parameters<typeof useNavigation>[1];
        };
        is_admin: boolean;
    }>();
    const project = page.props.project ?? page.props.sidebar_project;
    const { projectNav, teamNav } = useNavigation(
        project,
        page.props.sidebar_project?.counts,
        page.props.is_admin,
    );
    const path = page.url.split('?')[0];

    // Only on the pages themselves, not on what they lead to (one route, one job).
    const tabs =
        [...projectNav, ...teamNav].find((entry) =>
            entry.tabs?.some((tab) => tab.href === path),
        )?.tabs ?? [];

    // One page alone needs no tabs (a member's gear: only the data sources).
    return tabs.length > 1 ? tabs : null;
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
    'data-sources': dataSourcesIndex,
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
