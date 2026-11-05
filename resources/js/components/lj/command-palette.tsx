import { router, usePage } from '@inertiajs/react';
import { Command } from 'cmdk';
import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import {
    leaves,
    useNavigation,
    useProjectSwitchUrl,
} from '@/lib/use-navigation';
import { search } from '@/routes/projects';
import { switchMethod as switchTeam } from '@/routes/teams';
import type { ProjectHeader, TeamShared } from '@/types/lumberjack';

type Result = {
    group: string;
    label: string;
    detail: string | null;
    href: string;
};

const OPEN_EVENT = 'lj:command-palette';

export const openCommandPalette = () =>
    window.dispatchEvent(new Event(OPEN_EVENT));

export const shortcutLabel = () =>
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform)
        ? '⌘K'
        : 'Ctrl K';

const itemClass =
    'flex cursor-pointer items-center justify-between gap-3 rounded-md px-2.5 py-2 text-[13px] text-ink-2 data-[selected=true]:bg-panel-2 data-[selected=true]:text-ink';
const groupClass =
    '[&_[cmdk-group-heading]]:px-2.5 [&_[cmdk-group-heading]]:pt-2 [&_[cmdk-group-heading]]:pb-1 [&_[cmdk-group-heading]]:text-[11px] [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:tracking-wide [&_[cmdk-group-heading]]:text-ink-3 [&_[cmdk-group-heading]]:uppercase';

/**
 * Ctrl+K / Cmd+K: jump to any page, project or team, and search the project for
 * issues, routes, jobs, commands, scheduled tasks and traces.
 */
export function CommandPalette() {
    const t = useT();
    const page = usePage<{
        team: TeamShared | null;
        teams: { uuid: string; name: string }[];
        project?: ProjectHeader;
        sidebar_project: Pick<
            ProjectHeader,
            'uuid' | 'name' | 'can_administer'
        > | null;
        is_admin: boolean;
    }>();
    const { team, teams, is_admin } = page.props;
    const project =
        page.props.project ?? page.props.sidebar_project ?? undefined;
    const switchTo = useProjectSwitchUrl();
    const { projectNav, teamNav, adminNav } = useNavigation(
        project,
        undefined,
        is_admin,
    );
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Result[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key.toLowerCase() === 'k' && (e.metaKey || e.ctrlKey)) {
                e.preventDefault();
                setOpen((value) => !value);
            }
        };
        const onOpen = () => setOpen(true);
        window.addEventListener('keydown', onKey);
        window.addEventListener(OPEN_EVENT, onOpen);

        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener(OPEN_EVENT, onOpen);
        };
    }, []);

    // A new page closes the palette and starts the next search afresh.
    useEffect(() => {
        setOpen(false);
        setQuery('');
    }, [page.url]);

    const term = query.trim();
    const searchable = project !== undefined && term.length >= 2;

    useEffect(() => {
        if (!searchable || !open) {
            setResults([]);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            setLoading(true);
            fetch(search(project.uuid, { query: { q: term } }).url, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((data: { results: Result[] } | null) =>
                    setResults(data?.results ?? []),
                )
                .catch(() => {})
                .finally(() => setLoading(false));
        }, 200);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [searchable, open, term, project?.uuid]);

    const go = (href: string, external = false) => {
        setOpen(false);

        // A page outside the app (Horizon) is a full load, not an Inertia visit.
        if (external) {
            window.location.assign(href);

            return;
        }

        router.visit(href);
    };

    const groups = [...new Set(results.map((r) => r.group))];

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent className="top-[15vh] translate-y-0 gap-0 overflow-hidden border-line bg-panel p-0 sm:max-w-xl [&>button:last-child]:hidden">
                <DialogTitle className="sr-only">
                    {t('palette.title')}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    {t('palette.description')}
                </DialogDescription>
                <Command loop>
                    <Command.Input
                        value={query}
                        onValueChange={setQuery}
                        placeholder={
                            project
                                ? t(
                                      // A phone gets the short one: the long one is cut off.
                                      window.matchMedia('(max-width: 639px)')
                                          .matches
                                          ? 'palette.placeholder_project_short'
                                          : 'palette.placeholder_project',
                                      {
                                          project: project.name,
                                      },
                                  )
                                : t('palette.placeholder')
                        }
                        className="h-12 w-full border-b border-line bg-transparent px-4 text-sm text-ink outline-none placeholder:text-ink-3"
                    />
                    {/* The bottom fades out, a hint that the list scrolls on. */}
                    <Command.List className="max-h-[60vh] overflow-y-auto [mask-image:linear-gradient(to_bottom,black_calc(100%-1.5rem),transparent)] p-1.5 pb-6">
                        <Command.Empty className="px-3 py-6 text-center text-ink-3">
                            {loading
                                ? t('palette.searching')
                                : t('palette.empty')}
                        </Command.Empty>

                        {groups.map((group) => (
                            <Command.Group
                                key={group}
                                heading={t(`palette.groups.${group}`)}
                                className={groupClass}
                            >
                                {results
                                    .filter((r) => r.group === group)
                                    .map((r) => (
                                        <Command.Item
                                            // cmdk keeps an item's first keywords, so each search mounts its own.
                                            key={`${term} ${r.href}`}
                                            value={`${group} ${r.href}`}
                                            // The server already matched these.
                                            keywords={[term]}
                                            onSelect={() => go(r.href)}
                                            className={itemClass}
                                        >
                                            <span className="truncate">
                                                {r.label}
                                            </span>
                                            {r.detail && (
                                                <span className="shrink-0 font-mono text-[11px] text-ink-3">
                                                    {r.detail}
                                                </span>
                                            )}
                                        </Command.Item>
                                    ))}
                            </Command.Group>
                        ))}

                        {[
                            {
                                heading: project?.name,
                                items: leaves(projectNav),
                            },
                            {
                                heading: t('common.nav.team_section'),
                                items: leaves(teamNav),
                            },
                            {
                                heading: t('admin.nav.section'),
                                items: adminNav,
                            },
                        ]
                            .filter((section) => section.items.length > 0)
                            .map((section) => (
                                <Command.Group
                                    key={section.heading}
                                    heading={section.heading}
                                    className={groupClass}
                                >
                                    {section.items.map((item) => (
                                        <Command.Item
                                            key={item.href}
                                            value={`${section.heading} ${item.label}`}
                                            onSelect={() =>
                                                go(item.href, item.external)
                                            }
                                            className={itemClass}
                                        >
                                            {item.label}
                                        </Command.Item>
                                    ))}
                                </Command.Group>
                            ))}

                        {team && team.projects.length > 1 && (
                            <Command.Group
                                heading={t('palette.groups.projects')}
                                className={groupClass}
                            >
                                {team.projects.map((item) => (
                                    <Command.Item
                                        key={item.uuid}
                                        value={`project ${item.name} ${item.slug}`}
                                        onSelect={() => go(switchTo(item.uuid))}
                                        className={itemClass}
                                    >
                                        <span className="font-mono text-xs">
                                            {item.name}
                                        </span>
                                    </Command.Item>
                                ))}
                            </Command.Group>
                        )}

                        {teams.length > 1 && (
                            <Command.Group
                                heading={t('palette.groups.teams')}
                                className={groupClass}
                            >
                                {teams.map((item) => (
                                    <Command.Item
                                        key={item.uuid}
                                        value={`team ${item.name}`}
                                        onSelect={() => {
                                            setOpen(false);
                                            router.put(
                                                switchTeam(item.uuid).url,
                                            );
                                        }}
                                        className={itemClass}
                                    >
                                        {t('palette.switch_team', {
                                            team: item.name,
                                        })}
                                    </Command.Item>
                                ))}
                            </Command.Group>
                        )}
                    </Command.List>
                </Command>
            </DialogContent>
        </Dialog>
    );
}
