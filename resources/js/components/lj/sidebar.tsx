import { Link, router, usePage } from '@inertiajs/react';
import { Bell, BellOff, ChevronsUpDown, LogOut, UserCog } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { LogMark } from '@/components/lj/logo';
import { useT } from '@/lib/i18n';
import { useSoundSetting } from '@/lib/use-sound-setting';
import { cn } from '@/lib/utils';
import { audit as adminAudit } from '@/routes/admin';
import { index as adminTeams } from '@/routes/admin/teams';
import { index as adminUsers } from '@/routes/admin/users';
import { index as alertsIndex } from '@/routes/alerts';
import { index as commandsIndex } from '@/routes/commands';
import { index as issuesIndex } from '@/routes/issues';
import { index as jobsIndex } from '@/routes/jobs';
import { index as logsIndex } from '@/routes/logs';
import { edit as profileEdit } from '@/routes/profile';
import {
    index as projectsIndex,
    settings as projectSettings,
    show as projectShow,
} from '@/routes/projects';
import { index as queriesIndex } from '@/routes/queries';
import { index as routesIndex } from '@/routes/routes';
import { index as scheduleIndex } from '@/routes/schedule';
import { show as teamShow } from '@/routes/team';
import { switchMethod as switchTeam } from '@/routes/teams';
import { logout } from '@/routes';
import type { ProjectHeader, TeamShared } from '@/types/lumberjack';

type NavItem = { label: string; href: string; active: boolean; count?: number };

function NavLink({ item }: { item: NavItem }) {
    return (
        <Link
            href={item.href}
            aria-current={item.active ? 'page' : undefined}
            className={cn(
                'flex h-8 items-center justify-between rounded-md px-2.5 text-[13px] text-ink-2 hover:bg-panel-2 hover:text-ink',
                item.active &&
                    'bg-panel-2 font-medium text-ink shadow-[inset_2px_0_0_var(--lj-amber)]',
            )}
        >
            {item.label}
            {item.count !== undefined && item.count > 0 && (
                <span className="font-mono text-[11px] text-err">
                    {item.count > 99 ? '99+' : item.count}
                </span>
            )}
        </Link>
    );
}

export function Sidebar() {
    const t = useT();
    const [sound, setSound] = useSoundSetting();
    const page = usePage<{
        team: TeamShared | null;
        teams: { uuid: string; name: string }[];
        project?: ProjectHeader;
        sidebar_project:
            | (Pick<ProjectHeader, 'uuid' | 'name' | 'can_administer'> & {
                  counts: {
                      issues: number;
                      jobs: number;
                      schedule: number;
                      commands: number;
                  };
              })
            | null;
        auth: { user: { name: string } };
        is_admin: boolean;
    }>();
    const { team, teams, auth, is_admin } = page.props;
    const project =
        page.props.project ?? page.props.sidebar_project ?? undefined;
    const counts = page.props.sidebar_project?.counts;
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

    return (
        <nav
            aria-label={t('common.nav.label')}
            className="flex min-h-full w-full flex-col gap-5 border-r border-line bg-rail px-3 py-4 text-[13px] lg:h-full"
        >
            <Link
                href={projectsIndex().url}
                className="flex items-center gap-2.5 px-2 py-1 text-ink"
            >
                <LogMark />
                <span className="text-[15px] font-semibold tracking-tight">
                    Lumberjack
                </span>
            </Link>

            <div className="flex flex-col gap-1.5">
                <DropdownMenu>
                    <DropdownMenuTrigger className="flex h-9 w-full cursor-pointer items-center justify-between gap-2 rounded-md border border-line bg-panel px-2.5 text-left">
                        <span className="flex min-w-0 items-center gap-2">
                            <span
                                aria-hidden="true"
                                className="inline-flex size-5 items-center justify-center rounded bg-amber/15 text-[11px] font-semibold text-amber"
                            >
                                {team?.name.charAt(0).toUpperCase()}
                            </span>
                            <span className="truncate">
                                {team?.name ?? t('common.no_team')}
                            </span>
                        </span>
                        <ChevronsUpDown
                            className="size-3.5 text-ink-3"
                            aria-hidden="true"
                        />
                        <span className="sr-only">
                            {t('common.switch_team')}
                        </span>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-56">
                        <DropdownMenuLabel>
                            {t('common.teams')}
                        </DropdownMenuLabel>
                        {teams.map((item) => (
                            <DropdownMenuItem
                                key={item.uuid}
                                onSelect={() =>
                                    router.put(switchTeam(item.uuid).url)
                                }
                            >
                                {item.name}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>

                {team && team.projects.length > 0 && (
                    <DropdownMenu>
                        <DropdownMenuTrigger className="flex h-9 w-full cursor-pointer items-center justify-between gap-2 rounded-md border border-line bg-panel px-2.5 text-left">
                            <span className="truncate font-mono text-xs">
                                {project?.name ?? t('common.choose_project')}
                            </span>
                            <ChevronsUpDown
                                className="size-3.5 text-ink-3"
                                aria-hidden="true"
                            />
                            <span className="sr-only">
                                {t('common.switch_project')}
                            </span>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="w-56">
                            {team.projects.map((item) => (
                                <DropdownMenuItem key={item.uuid} asChild>
                                    <Link
                                        href={projectShow(item.uuid).url}
                                        className="font-mono text-xs"
                                    >
                                        {item.name}
                                    </Link>
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>

            {projectNav.length > 0 && (
                <div className="flex flex-col gap-0.5">
                    <span className="px-2.5 pb-1.5 text-[11px] font-medium tracking-wide text-ink-3 uppercase">
                        {t('common.nav.project')}
                    </span>
                    {projectNav.map((item) => (
                        <NavLink key={item.href} item={item} />
                    ))}
                </div>
            )}

            <div className="flex flex-col gap-0.5">
                <span className="px-2.5 pb-1.5 text-[11px] font-medium tracking-wide text-ink-3 uppercase">
                    {t('common.nav.team_section')}
                </span>
                {teamNav.map((item) => (
                    <NavLink key={item.href} item={item} />
                ))}
            </div>

            {adminNav.length > 0 && (
                <div className="flex flex-col gap-0.5">
                    <span className="px-2.5 pb-1.5 text-[11px] font-medium tracking-wide text-ink-3 uppercase">
                        {t('admin.nav.section')}
                    </span>
                    {adminNav.map((item) => (
                        <NavLink key={item.href} item={item} />
                    ))}
                </div>
            )}

            <DropdownMenu>
                <DropdownMenuTrigger className="mt-auto flex cursor-pointer items-center gap-2.5 border-t border-line px-2.5 pt-3 text-left">
                    <span
                        aria-hidden="true"
                        className="inline-flex size-7 items-center justify-center rounded-full border border-line-strong bg-panel-2 text-[11px] font-semibold text-ink-2"
                    >
                        {auth.user.name
                            .split(' ')
                            .map((part) => part.charAt(0))
                            .join('')
                            .slice(0, 2)
                            .toUpperCase()}
                    </span>
                    <span className="flex min-w-0 flex-col">
                        <span className="truncate font-medium">
                            {auth.user.name}
                        </span>
                        <span className="text-[11px] text-ink-3">
                            {team?.visiting
                                ? t('common.roles.system_admin')
                                : team?.role
                                  ? t(`common.roles.${team.role}`)
                                  : ''}
                        </span>
                    </span>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-56">
                    <DropdownMenuItem asChild>
                        <Link href={profileEdit().url}>
                            <UserCog className="size-4" aria-hidden="true" />
                            {t('common.profile')}
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={(event) => {
                            event.preventDefault();
                            setSound(!sound);
                        }}
                    >
                        {sound ? (
                            <Bell className="size-4" aria-hidden="true" />
                        ) : (
                            <BellOff className="size-4" aria-hidden="true" />
                        )}
                        {sound ? t('common.sound_on') : t('common.sound_off')}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem asChild>
                        <Link
                            href={logout().url}
                            method="post"
                            as="button"
                            className="w-full"
                        >
                            <LogOut className="size-4" aria-hidden="true" />
                            {t('common.log_out')}
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </nav>
    );
}
