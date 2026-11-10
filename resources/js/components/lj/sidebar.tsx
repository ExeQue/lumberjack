import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    ChevronRight,
    ChevronsUpDown,
    ExternalLink,
    LogOut,
    Search,
    UserCog,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { NotificationBell } from '@/components/lj/notification-bell';
import {
    openCommandPalette,
    shortcutLabel,
} from '@/components/lj/command-palette';
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
import { navActiveClass } from '@/components/lj/ui';
import { cn } from '@/lib/utils';
import { useNavigation, useProjectSwitchUrl } from '@/lib/use-navigation';
import type { NavItem } from '@/lib/use-navigation';
import { about } from '@/routes';
import { edit as profileEdit } from '@/routes/profile';
import { index as projectsIndex } from '@/routes/projects';
import { switchMethod as switchTeam } from '@/routes/teams';
import { logout } from '@/routes';
import type { ProjectHeader, TeamShared } from '@/types/lumberjack';

function NavLink({ item }: { item: NavItem }) {
    if (item.external) {
        return (
            <a
                href={item.href}
                className="lj-nav-item flex h-8 items-center justify-between rounded-md px-2.5 text-[13px] text-ink-2 hover:bg-panel-2 hover:text-ink [@media(max-height:800px)]:h-7"
            >
                {item.label}
                <ExternalLink
                    className="size-3 text-ink-3"
                    aria-hidden="true"
                />
            </a>
        );
    }

    const link = (
        <Link
            href={item.href}
            aria-current={item.active ? 'page' : undefined}
            className={cn(
                'lj-nav-item flex h-8 items-center justify-between rounded-md px-2.5 text-[13px] text-ink-2 hover:bg-panel-2 hover:text-ink [@media(max-height:800px)]:h-7',
                item.active && navActiveClass,
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

    if (!item.tabs || item.tabs.length < 2) {
        return link;
    }

    // A group's pages fold out below it on hover or focus, and stay out while one is open.
    return (
        <div className="group/nav flex flex-col">
            {link}
            <div
                className={cn(
                    'grid grid-rows-[0fr] transition-[grid-template-rows] duration-150 group-focus-within/nav:grid-rows-[1fr] group-hover/nav:grid-rows-[1fr]',
                    item.active && 'grid-rows-[1fr]',
                )}
            >
                <div className="flex flex-col gap-0.5 overflow-hidden pl-3">
                    {item.tabs.map((tab) => (
                        <Link
                            key={tab.href}
                            href={tab.href}
                            aria-current={tab.active ? 'page' : undefined}
                            className={cn(
                                'mt-0.5 flex h-7 items-center justify-between rounded-md px-2.5 text-xs text-ink-3 hover:bg-panel-2 hover:text-ink',
                                tab.active && 'text-ink',
                            )}
                        >
                            {tab.label}
                            {tab.count !== undefined && tab.count > 0 && (
                                <span className="font-mono text-[11px] text-err">
                                    {tab.count > 99 ? '99+' : tab.count}
                                </span>
                            )}
                        </Link>
                    ))}
                </div>
            </div>
        </div>
    );
}

/** `bell`: whether the notification bell sits in the header; the top bar has it otherwise. */
export function Sidebar({ bell = true }: { bell?: boolean }) {
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
                      health: number;
                      commands: number;
                  };
              })
            | null;
        auth: { user: { name: string } };
        is_admin: boolean;
        app_version: string;
        update_available: string | null;
    }>();
    const {
        team,
        teams,
        auth,
        is_admin,
        app_version: appVersion,
        update_available: updateAvailable,
    } = page.props;
    const project =
        page.props.project ?? page.props.sidebar_project ?? undefined;
    const counts = page.props.sidebar_project?.counts;
    const switchTo = useProjectSwitchUrl();
    const { projectNav, teamNav, adminNav } = useNavigation(
        project,
        counts,
        is_admin,
    );
    const links = useRef<HTMLDivElement>(null);

    // On a short screen the open page's entry may be below the fold: bring it up.
    useEffect(() => {
        // Only this list scrolls; scrollIntoView would move the page too.
        const box = links.current;
        const item = box?.querySelector('[aria-current="page"]');

        if (box && item) {
            const top =
                item.getBoundingClientRect().top -
                box.getBoundingClientRect().top;

            if (top < 0 || top + item.clientHeight > box.clientHeight) {
                box.scrollTop += top - box.clientHeight / 2;
            }
        }
    }, []);

    return (
        <nav
            aria-label={t('common.nav.label')}
            className="flex min-h-full w-full flex-col gap-5 bg-rail px-3 py-4 text-[13px] lg:h-full [@media(max-height:800px)]:gap-3 [@media(max-height:800px)]:py-3"
        >
            <div className="flex items-center justify-between gap-2">
                <Link
                    href={projectsIndex().url}
                    className="flex items-center gap-2.5 px-2 py-1 text-ink"
                >
                    <LogMark />
                    <span className="text-[15px] font-semibold tracking-tight">
                        Lumberjack
                    </span>
                </Link>
                {bell && <NotificationBell />}
            </div>

            <div className="flex flex-col gap-1.5">
                <button
                    type="button"
                    onClick={openCommandPalette}
                    className="flex h-9 w-full cursor-pointer items-center gap-2 rounded-md border border-line bg-panel px-2.5 text-left text-ink-3 hover:border-line-strong hover:text-ink-2"
                >
                    <Search className="size-3.5" aria-hidden="true" />
                    <span className="flex-1">{t('palette.open')}</span>
                    <kbd className="rounded border border-line px-1 font-mono text-[10px]">
                        {shortcutLabel()}
                    </kbd>
                </button>
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
                    <div className="flex gap-1.5">
                        <DropdownMenu>
                            <DropdownMenuTrigger className="flex h-9 min-w-0 flex-1 cursor-pointer items-center justify-between gap-2 rounded-md border border-line bg-panel px-2.5 text-left">
                                <span className="truncate font-mono text-xs">
                                    {project?.name ??
                                        t('common.choose_project')}
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
                                            href={switchTo(item.uuid)}
                                            className="font-mono text-xs"
                                        >
                                            {item.name}
                                        </Link>
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                )}
            </div>

            {/* The links scroll between the switchers and the user; a fade hints at more below. */}
            <div
                ref={links}
                className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto [mask-image:linear-gradient(to_bottom,black_calc(100%-1.5rem),transparent)] pb-6 [@media(max-height:800px)]:gap-3"
            >
                {projectNav.length > 0 && (
                    <div className="flex flex-col gap-0.5">
                        <span className="px-2.5 pb-1.5 text-[11px] font-medium tracking-wide text-ink-3 uppercase">
                            {t('common.nav.project')}
                        </span>
                        {projectNav
                            .filter((item) => !item.hidden)
                            .map((item) => (
                                <NavLink key={item.href} item={item} />
                            ))}
                    </div>
                )}

                <div className="flex flex-col gap-0.5">
                    <span className="px-2.5 pb-1.5 text-[11px] font-medium tracking-wide text-ink-3 uppercase">
                        {t('common.nav.team_section')}
                    </span>
                    {teamNav
                        .filter((item) => !item.hidden)
                        .map((item) => (
                            <NavLink key={item.href} item={item} />
                        ))}
                </div>

                {adminNav.length > 0 && (
                    // Folded away unless an admin page is open: most days it is not needed.
                    <details
                        open={adminNav.some((item) => item.active) || undefined}
                        className="group flex flex-col gap-0.5"
                    >
                        <summary className="flex cursor-pointer list-none items-center justify-between px-2.5 pb-1.5 text-[11px] font-medium tracking-wide text-ink-3 uppercase hover:text-ink-2 [&::-webkit-details-marker]:hidden">
                            {t('admin.nav.section')}
                            <ChevronRight
                                className="size-3 transition-transform group-open:rotate-90"
                                aria-hidden="true"
                            />
                        </summary>
                        {adminNav.map((item) => (
                            <NavLink key={item.href} item={item} />
                        ))}
                    </details>
                )}
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger className="flex cursor-pointer items-center gap-2.5 border-t border-line px-2.5 pt-3 text-left">
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
                <DropdownMenuContent side="top" align="start" className="w-56">
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
            {/* The running version, small, under the user; it opens About. */}
            <Link
                href={about().url}
                title={t('about.title')}
                className="-mt-3 shrink-0 truncate px-2.5 font-mono text-[11px] text-ink-3 hover:text-ink-2"
            >
                {appVersion}
                {updateAvailable && (
                    <span className="ml-1.5 text-amber">
                        {t('about.update.sidebar', {
                            version: updateAvailable,
                        })}
                    </span>
                )}
            </Link>
        </nav>
    );
}
