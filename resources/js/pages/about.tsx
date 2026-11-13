import { Deferred, Head, router } from '@inertiajs/react';
import { ChevronRight, ExternalLink } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { MouseEvent } from 'react';
import Markdown from 'react-markdown';
import { LogMark } from '@/components/lj/logo';
import { Relative } from '@/components/lj/relative';
import { TopBar } from '@/components/lj/top-bar';
import { Button, Card, CardHeader, PageTitle } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import { updateCheck } from '@/routes/admin';

type Props = {
    version: string;
    revision: string | null;
    /** The release notes, for a tagged release. */
    release_url: string | null;
    changelog_url: string;
    docs_url: string;
    repository_url: string;
    /** The changelog this build carries, newest first; deferred. */
    changelog?: Release[];
    /** The last check for a newer release; for system administrators, unless checks are off. */
    update: {
        latest: { version: string; checked_at: string } | null;
        url: string | null;
        available: boolean;
    } | null;
    /** What it runs on; for system administrators only. */
    system: Record<string, string | null> | null;
};

type Release = { version: string; date: string | null; notes: string };

/** Which Lumberjack this is and where to read about it. */
export default function About({
    version,
    revision,
    release_url: releaseUrl,
    changelog_url: changelogUrl,
    docs_url: docsUrl,
    repository_url: repositoryUrl,
    changelog,
    update,
    system,
}: Props) {
    const t = useT();
    const [checking, setChecking] = useState(false);
    const checkNow = () =>
        router.post(
            updateCheck().url,
            {},
            {
                preserveScroll: true,
                onStart: () => setChecking(true),
                onFinish: () => setChecking(false),
            },
        );
    const link =
        'inline-flex items-center gap-1 py-1 text-amber hover:underline';

    return (
        <>
            <Head title={t('about.title')} />
            <TopBar crumbs={[{ label: t('about.title') }]} />
            <PageBody>
                <PageTitle
                    title={t('about.title')}
                    description={t('about.description')}
                />
                <Card>
                    <div className="flex items-center gap-4 p-5">
                        <span
                            aria-hidden="true"
                            className="flex size-12 items-center justify-center rounded-lg bg-panel-2 [&_svg]:size-7"
                        >
                            <LogMark />
                        </span>
                        <div className="flex min-w-0 flex-col gap-1">
                            <span className="text-lg font-semibold">
                                Lumberjack{' '}
                                <span className="font-mono">{version}</span>
                            </span>
                            {revision && (
                                <span className="font-mono text-xs text-ink-3">
                                    {t('about.revision', { revision })}
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-x-5 gap-y-2 border-t border-line px-5 py-3 text-sm">
                        {releaseUrl && (
                            <a
                                href={releaseUrl}
                                className={link}
                                target="_blank"
                                rel="noreferrer"
                            >
                                {t('about.release_notes')}
                                <ExternalLink
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                            </a>
                        )}
                        <a
                            href={changelogUrl}
                            className={link}
                            target="_blank"
                            rel="noreferrer"
                        >
                            {t('about.changelog')}
                            <ExternalLink
                                className="size-3.5"
                                aria-hidden="true"
                            />
                        </a>
                        <a
                            href={docsUrl}
                            className={link}
                            target="_blank"
                            rel="noreferrer"
                        >
                            {t('about.docs')}
                            <ExternalLink
                                className="size-3.5"
                                aria-hidden="true"
                            />
                        </a>
                        <a
                            href={repositoryUrl}
                            className={link}
                            target="_blank"
                            rel="noreferrer"
                        >
                            {t('about.source')}
                            <ExternalLink
                                className="size-3.5"
                                aria-hidden="true"
                            />
                        </a>
                    </div>
                    {update && (
                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3 text-sm">
                            <span className="text-ink-2">
                                {update.latest === null ? (
                                    t('about.update.never_checked')
                                ) : update.available ? (
                                    <a
                                        href={update.url ?? undefined}
                                        className={link}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {t('about.update.available', {
                                            version: update.latest.version,
                                        })}
                                        <ExternalLink
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                    </a>
                                ) : (
                                    t(
                                        version.replace(/^v/, '') ===
                                            update.latest.version
                                            ? 'about.update.current'
                                            : 'about.update.latest',
                                        { version: update.latest.version },
                                    )
                                )}
                                {update.latest && (
                                    <span className="ml-2 text-ink-3">
                                        {t('about.update.checked')}{' '}
                                        <Relative
                                            iso={update.latest.checked_at}
                                        />
                                    </span>
                                )}
                            </span>
                            <Button onClick={checkNow} disabled={checking}>
                                {t('about.update.check')}
                            </Button>
                        </div>
                    )}
                </Card>
                <Deferred
                    data="changelog"
                    fallback={
                        <Card>
                            <div className="flex flex-col gap-3 p-5">
                                <div className="h-4 w-40 animate-pulse rounded bg-panel-2" />
                                <div className="h-24 animate-pulse rounded bg-panel-2" />
                            </div>
                        </Card>
                    }
                >
                    <ReleaseNotes
                        releases={changelog ?? []}
                        running={version.replace(/^v/, '')}
                    />
                </Deferred>
                {system && (
                    <Card>
                        <CardHeader
                            title={t('about.system.title')}
                            description={t('about.system.description')}
                        />
                        <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 p-5 text-sm">
                            {Object.entries(system).map(([key, value]) => (
                                <div key={key} className="contents">
                                    <dt className="text-ink-3">
                                        {t(`about.system.${key}`)}
                                    </dt>
                                    <dd className="font-mono">
                                        {value ?? '-'}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </Card>
                )}
            </PageBody>
        </>
    );
}

/** Releases with a chip before the rest are asked for. */
const CHIPS = 5;

/** Releases listed before the rest are asked for. */
const LISTED = 10;

const anchor = (version: string) =>
    `release-${version.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;

/**
 * The changelog by release: the versions to jump to on top, then each release folded,
 * the running one and anything unreleased out.
 */
function ReleaseNotes({
    releases,
    running,
}: {
    releases: Release[];
    running: string;
}) {
    const t = useT();
    const [allChips, setAllChips] = useState(false);
    // Unreleased and the five newest releases; the rest behind a button.
    const shownChips = CHIPS + (releases[0]?.version === 'Unreleased' ? 1 : 0);
    const chips = allChips ? releases : releases.slice(0, shownChips);
    const hidden = releases.length - chips.length;
    const nav = useRef<HTMLElement>(null);
    const [allReleases, setAllReleases] = useState(false);
    // Unreleased and the ten newest, and never short of the running one.
    const listed = Math.max(
        LISTED + (releases[0]?.version === 'Unreleased' ? 1 : 0),
        releases.findIndex((r) => r.version === running) + 1,
    );
    const shownReleases = allReleases ? releases : releases.slice(0, listed);
    const moreReleases = releases.length - shownReleases.length;
    const focusFrom = useRef<number | null>(null);
    // A release asked for (a chip or the address) that is not listed yet: opened once it is.
    const [pending, setPending] = useState<string | null>(null);
    // Opens the release and brings it up just below the sticky row, however many lines that wraps to.
    const reveal = useCallback((id: string, smooth: boolean): boolean => {
        const target = document.getElementById(id);

        if (!(target instanceof HTMLDetailsElement)) {
            return false;
        }

        target.open = true;
        // Below the row and whatever it sticks under (the phone's header).
        const row = nav.current;
        const stuckAt = row ? parseFloat(getComputedStyle(row).top) || 0 : 0;
        target.style.scrollMarginTop = `${stuckAt + (row?.offsetHeight ?? 0) + 8}px`;
        target.scrollIntoView({
            behavior:
                smooth &&
                !window.matchMedia('(prefers-reduced-motion: reduce)').matches
                    ? 'smooth'
                    : 'auto',
            block: 'start',
        });

        return true;
    }, []);
    // The address keeps the anchor, so it can be shared.
    const jump = (event: MouseEvent, release: Release) => {
        if (!shownReleases.includes(release)) {
            event.preventDefault();
            setAllReleases(true);
            setPending(anchor(release.version));

            return;
        }

        if (reveal(anchor(release.version), true)) {
            event.preventDefault();
            // Into the release, so the keyboard and a screen reader go on from there.
            document
                .getElementById(anchor(release.version))
                ?.querySelector('summary')
                ?.focus({ preventScroll: true });
            history.replaceState(
                history.state,
                '',
                `#${anchor(release.version)}`,
            );
        }
    };

    // The button goes once clicked: the first chip it showed takes the focus, not the page.
    useEffect(() => {
        if (allChips) {
            nav.current
                ?.querySelectorAll<HTMLAnchorElement>('a')
                [shownChips]?.focus();
        }
    }, [allChips, shownChips]);

    // An address with a release's anchor opens it once the changelog is there, listing them all if need be.
    useEffect(() => {
        if (
            window.location.hash.startsWith('#release-') &&
            !reveal(window.location.hash.slice(1), false)
        ) {
            setAllReleases(true);
            setPending(window.location.hash.slice(1));
        }
    }, [reveal]);

    useEffect(() => {
        if (allReleases && focusFrom.current !== null) {
            document
                .getElementById(
                    anchor(releases[focusFrom.current]?.version ?? ''),
                )
                ?.querySelector('summary')
                ?.focus();
            focusFrom.current = null;
        }
    }, [allReleases, releases]);

    // Opens the release asked for once it is listed, and moves into it.
    useEffect(() => {
        if (pending !== null && allReleases && reveal(pending, true)) {
            document
                .getElementById(pending)
                ?.querySelector('summary')
                ?.focus({ preventScroll: true });
            history.replaceState(history.state, '', `#${pending}`);
            setPending(null);
        }
    }, [pending, allReleases, reveal]);
    const name = (release: Release) =>
        release.version === 'Unreleased'
            ? t('about.notes.unreleased')
            : release.version;

    return (
        <Card>
            <CardHeader
                title={t('about.notes.title')}
                description={t('about.notes.description')}
            />
            {releases.length === 0 ? (
                <p className="p-5 text-sm text-ink-3">
                    {t('about.notes.empty')}
                </p>
            ) : (
                <>
                    <nav
                        ref={nav}
                        aria-label={t('about.notes.jump')}
                        className="sticky top-12 z-10 flex flex-wrap gap-1.5 border-b border-line bg-panel px-5 py-3 lg:top-0"
                    >
                        {chips.map((release) => (
                            <a
                                key={release.version}
                                href={`#${anchor(release.version)}`}
                                onClick={(event) => jump(event, release)}
                                className="inline-flex items-center gap-1.5 rounded-md border border-line px-2 py-0.5 font-mono text-xs text-ink-2 hover:border-amber hover:text-ink"
                            >
                                {release.version === running && (
                                    <span
                                        aria-hidden="true"
                                        className="size-1.5 rounded-full bg-amber"
                                    />
                                )}
                                {name(release)}
                                {release.version === running && (
                                    <span className="sr-only">
                                        {t('about.notes.running')}
                                    </span>
                                )}
                            </a>
                        ))}
                        {hidden > 0 && (
                            <button
                                type="button"
                                onClick={() => setAllChips(true)}
                                className="cursor-pointer rounded-md px-2 py-0.5 text-xs text-amber hover:underline"
                            >
                                {t('about.notes.more', { count: hidden })}
                            </button>
                        )}
                    </nav>
                    <div className="flex flex-col divide-y divide-line">
                        {shownReleases.map((release) => (
                            <details
                                key={release.version}
                                id={anchor(release.version)}
                                open={
                                    release.version === 'Unreleased' ||
                                    release.version === running
                                }
                                className="group"
                            >
                                <summary className="flex cursor-pointer list-none items-center gap-2 px-5 py-3 hover:bg-panel-2 [&::-webkit-details-marker]:hidden">
                                    <ChevronRight
                                        className="size-3 text-ink-3 transition-transform group-open:rotate-90"
                                        aria-hidden="true"
                                    />
                                    <span className="font-mono font-medium">
                                        {name(release)}
                                    </span>
                                    {release.version === running && (
                                        <span className="rounded bg-amber/15 px-1.5 text-xs text-amber">
                                            {t('about.notes.running')}
                                        </span>
                                    )}
                                    {release.date && (
                                        <span className="ml-auto font-mono text-xs text-ink-3">
                                            {release.date}
                                        </span>
                                    )}
                                </summary>
                                <div className="flex flex-col gap-2 px-5 pb-4 text-sm text-ink-2 [&_code]:rounded [&_code]:bg-panel-2 [&_code]:px-1 [&_code]:font-mono [&_code]:text-xs [&_h3]:mt-2 [&_h3]:text-xs [&_h3]:font-medium [&_h3]:tracking-wide [&_h3]:text-ink-3 [&_h3]:uppercase [&_li]:ml-4 [&_li]:list-disc [&_strong]:text-ink [&_ul]:flex [&_ul]:flex-col [&_ul]:gap-1">
                                    <Markdown
                                        components={{
                                            a: ({ href, children }) => (
                                                <a
                                                    href={href}
                                                    className="text-amber hover:underline"
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    {children}
                                                </a>
                                            ),
                                        }}
                                    >
                                        {release.notes}
                                    </Markdown>
                                </div>
                            </details>
                        ))}
                    </div>
                    {moreReleases > 0 && (
                        <div className="border-t border-line px-5 py-3">
                            <button
                                type="button"
                                onClick={() => {
                                    setAllReleases(true);
                                    // The first release it listed takes the focus, still folded.
                                    focusFrom.current = shownReleases.length;
                                }}
                                className="cursor-pointer text-xs text-amber hover:underline"
                            >
                                {t('about.notes.more', {
                                    count: moreReleases,
                                })}
                            </button>
                        </div>
                    )}
                </>
            )}
        </Card>
    );
}
