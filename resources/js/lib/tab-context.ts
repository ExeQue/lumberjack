import { router } from '@inertiajs/react';

/**
 * Each browser tab keeps its own team and project: remembered in sessionStorage (one per
 * tab) and sent with every request, where UseTabTeam makes it the team for that request.
 * The team stored on the user is only where a new tab starts.
 */
const KEY = 'lj.tab';

type Tab = { team?: string; project?: string };

type PageProps = {
    team?: { uuid: string } | null;
    project?: { uuid: string } | null;
    sidebar_project?: { uuid: string } | null;
};

function read(): Tab {
    try {
        return JSON.parse(sessionStorage.getItem(KEY) ?? '{}') as Tab;
    } catch {
        return {};
    }
}

function write(tab: Tab): void {
    try {
        sessionStorage.setItem(KEY, JSON.stringify(tab));
    } catch {
        // No storage (a private window that refuses it): tabs share the team, as before.
    }
}

/** The headers that tell the server which team and project this tab shows. */
export function tabHeaders(): Record<string, string> {
    const tab = read();

    return {
        ...(tab.team ? { 'X-Lumberjack-Team': tab.team } : {}),
        ...(tab.project ? { 'X-Lumberjack-Project': tab.project } : {}),
    };
}

export function trackTabContext(): void {
    let first = true;

    router.on('before', (event) => {
        event.detail.visit.headers = {
            ...event.detail.visit.headers,
            ...tabHeaders(),
        };
    });

    router.on('navigate', (event) => {
        const props = event.detail.page.props as PageProps;
        const stored = read();

        // A full load (a new tab, F5) cannot send the headers, so it shows the user's
        // stored team. A tab that had another one asks again, once, with its own. A
        // project page always shows its project's team, so it is taken as it is.
        if (
            first &&
            stored.team &&
            props.team &&
            stored.team !== props.team.uuid &&
            !props.project
        ) {
            first = false;
            router.reload();

            return;
        }

        first = false;
        remember(props);
    });

    // A visit that is not a navigation of its own (the team switch's redirect) lands too.
    router.on('success', (event) => {
        remember(event.detail.page.props as PageProps);
    });
}

function remember(props: PageProps): void {
    if (props.team) {
        write({
            team: props.team.uuid,
            project: props.project?.uuid ?? props.sidebar_project?.uuid,
        });
    }
}
