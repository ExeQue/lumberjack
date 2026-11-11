import { usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import {
    audit as adminAudit,
    retention as adminRetention,
} from '@/routes/admin';
import { index as adminTeams } from '@/routes/admin/teams';
import { index as adminUsers } from '@/routes/admin/users';
import { index as alertsIndex } from '@/routes/alerts';
import { index as browserIndex } from '@/routes/browser';
import { index as commandsIndex } from '@/routes/commands';
import { index as dataSourcesIndex } from '@/routes/data-sources';
import { index as healthIndex } from '@/routes/health';
import { index as issuesIndex } from '@/routes/issues';
import { index as jobsIndex } from '@/routes/jobs';
import { index as logsIndex } from '@/routes/logs';
import { index as notificationsIndex } from '@/routes/notifications';
import { about } from '@/routes';
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
import { index as connectorsIndex } from '@/routes/connectors';
import { index as githubIndex } from '@/routes/connectors/github';
import { index as mobilepayIndex } from '@/routes/connectors/mobilepay';
import { index as mosparoIndex } from '@/routes/connectors/mosparo';
import { index as umamiIndex } from '@/routes/connectors/umami';
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
    /** A group whose pages fold out in the sidebar only, without tabs above them. */
    sidebarOnly?: boolean;
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

/** The connectors with a page, by their key in the catalog: the sidebar label and the page. */
const CONNECTOR_PAGES: Record<
    string,
    [label: string, page: typeof umamiIndex]
> = {
    umami: ['common.nav.umami', umamiIndex],
    mosparo: ['common.nav.mosparo', mosparoIndex],
    github: ['common.nav.github', githubIndex],
    mobilepay: ['common.nav.mobilepay', mobilepayIndex],
};

/**
 * Every page behind the entries, one per page: for the command palette. A sidebar-only
 * group, such as Connectors, is a page of its own too.
 */
export function leaves(items: NavItem[]): NavItem[] {
    return items.flatMap((item) =>
        item.sidebarOnly
            ? [{ ...item, tabs: undefined }, ...(item.tabs ?? [])]
            : (item.tabs ?? [item]),
    );
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
    const page = usePage<{
        sidebar_project?: {
            /** Each connected connector's connections, by its key, in name order. */
            connectors?: Record<string, { uuid: string; name: string }[]>;
        } | null;
    }>();
    // The routed project on a project page, so it is the one these entries are for.
    const connected = page.props.sidebar_project?.connectors ?? {};
    // Without the query or a #section (the settings page keeps its section there).
    const path = page.url.split(/[?#]/)[0];
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

    // One entry per connection, by connector then connection name; the connector's name alone
    // when it has one connection.
    const connectors: NavItem[] = project
        ? Object.entries(CONNECTOR_PAGES)
              .map(([key, [label, pageOf]]) => ({
                  key,
                  label: t(label),
                  pageOf,
              }))
              .toSorted((a, b) =>
                  a.label.localeCompare(b.label, undefined, {
                      sensitivity: 'base',
                  }),
              )
              .flatMap(({ key, label, pageOf }) => {
                  const connections = connected[key] ?? [];
                  const base = pageOf({ project: project.uuid }).url;

                  if (connections.length === 1) {
                      return [item(label, base)];
                  }

                  return connections.map((connection, i) => {
                      const href = pageOf([project.uuid, connection.uuid]).url;

                      return {
                          label: t('common.nav.connector_connection', {
                              connector: label,
                              connection: connection.name,
                          }),
                          href,
                          // Without one picked, the page shows the first by name.
                          active: isAt(href) || (i === 0 && path === base),
                      };
                  });
              })
        : [];

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
              item(t('common.nav.browser'), browserIndex(project.uuid).url),
              item(t('common.nav.jobs'), jobsIndex(project.uuid).url, {
                  count: counts?.jobs,
              }),
              group(t('common.nav.console'), [
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
              // The services the project reads, last before its settings: the label opens the
              // Connectors page, the chevron folds out those connected.
              {
                  ...item(
                      t('common.nav.connectors'),
                      connectorsIndex(project.uuid).url,
                  ),
                  tabs: connectors,
                  sidebarOnly: true,
              },
              // Last in the list: the settings (with the data sources in their menu) for
              // whoever administers the project, else the data sources, read only.
              {
                  ...item(
                      project.can_administer
                          ? t('common.nav.settings')
                          : t('common.nav.data_sources'),
                      project.can_administer
                          ? projectSettings(project.uuid).url
                          : dataSourcesIndex(project.uuid).url,
                  ),
                  active:
                      isAt(projectSettings(project.uuid).url) ||
                      isAt(dataSourcesIndex(project.uuid).url),
              },
              // For admins, behind Settings: not in the sidebar's list, but in the palette.
              ...(project.can_administer
                  ? [
                        item(
                            t('common.nav.data_sources'),
                            dataSourcesIndex(project.uuid).url,
                            { hidden: true },
                        ),
                    ]
                  : []),
          ]
        : [];

    const teamNav: NavItem[] = [
        item(t('common.nav.projects'), projectsIndex().url, { exact: true }),
        group(t('common.nav.screens'), [
            // Exact: not on the screens below it, or another tab of the section.
            item(t('common.nav.screens'), screensIndex().url, { exact: true }),
            item(t('common.nav.displays'), teamDisplays().url),
        ]),
        item(t('common.nav.alerts'), alertsIndex().url),
        group(t('common.nav.team_page'), [
            item(t('common.nav.team'), teamShow().url, { exact: true }),
            item(t('common.nav.retention'), teamRetention().url),
        ]),
        // The bell opens it; the command palette lists it.
        item(t('common.nav.notifications'), notificationsIndex().url, {
            hidden: true,
        }),
        // Under the user menu; the command palette lists it too.
        item(t('about.title'), about().url, { hidden: true }),
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

    // On a tab's page and below it; a tab that must not reach further, such as Screens
    // over a screen, is exact. A sidebar-only group, such as Connectors, has none.
    const tabs =
        [...projectNav, ...teamNav].find(
            (entry) =>
                !entry.sidebarOnly && entry.tabs?.some((tab) => tab.active),
        )?.tabs ?? [];

    // One page alone needs no tabs (a member's gear: only the data sources).
    return tabs.length > 1 ? tabs : null;
}

const sections: Record<string, (project: string) => { url: string }> = {
    issues: issuesIndex,
    requests: routesIndex,
    database: queriesIndex,
    browser: browserIndex,
    umami: (project) => umamiIndex({ project }),
    mosparo: (project) => mosparoIndex({ project }),
    github: (project) => githubIndex({ project }),
    mobilepay: (project) => mobilepayIndex({ project }),
    releases: releasesIndex,
    jobs: jobsIndex,
    schedule: scheduleIndex,
    commands: commandsIndex,
    logs: logsIndex,
    health: healthIndex,
    connectors: connectorsIndex,
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
