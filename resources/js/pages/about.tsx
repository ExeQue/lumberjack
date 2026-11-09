import { Head, router } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
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
    /** The last check for a newer release; for system administrators, unless checks are off. */
    update: {
        latest: { version: string; checked_at: string } | null;
        url: string | null;
        available: boolean;
    } | null;
    /** What it runs on; for system administrators only. */
    system: Record<string, string | null> | null;
};

/** Which Lumberjack this is and where to read about it. */
export default function About({
    version,
    revision,
    release_url: releaseUrl,
    changelog_url: changelogUrl,
    docs_url: docsUrl,
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
