import { Head } from '@inertiajs/react';
import { TopBar } from '@/components/lj/top-bar';
import { Card, EmptyState } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';

/**
 * Someone with an account but no team: there is nothing to show until they are invited.
 */
export default function TeamNone() {
    const t = useT();

    return (
        <>
            <Head title={t('team.none.title')} />
            <TopBar crumbs={[{ label: t('team.none.title') }]} />
            <PageBody>
                <Card>
                    <EmptyState title={t('team.none.title')}>
                        <p className="max-w-md text-[13px] text-ink-2">
                            {t('team.none.body')}
                        </p>
                    </EmptyState>
                </Card>
            </PageBody>
        </>
    );
}
