import { Head, useForm } from '@inertiajs/react';
import {
    RetentionTable,
    retentionFormData,
    retentionPayload,
} from '@/components/lj/retention-table';
import { LiveNotice } from '@/components/lj/live-notice';
import { TopBar } from '@/components/lj/top-bar';
import { Button, Card, PageTitle } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useAdminLive, useFormSync } from '@/lib/use-live';
import { update as retentionUpdate } from '@/routes/admin/retention';
import type { RetentionRow, RetentionStats } from '@/types/lumberjack';

export default function AdminRetention({
    retention,
    retention_stats,
}: {
    retention: RetentionRow[];
    /** Deferred: estimated after the page shows. */
    retention_stats?: RetentionStats;
}) {
    const t = useT();
    const form = useForm(retentionFormData(retention));
    useFormSync(form, retentionFormData(retention));
    const live = useAdminLive(['retention.changed'], ['retention'], {
        guard: true,
    });

    return (
        <>
            <Head title={t('admin.retention.title')} />
            <TopBar
                crumbs={[
                    { label: t('admin.nav.section') },
                    { label: t('admin.retention.title') },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={t('admin.retention.title')}
                    description={t('admin.retention.description')}
                />
                <Card>
                    <form
                        className="flex flex-col gap-4 p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.transform(retentionPayload);
                            form.submit(retentionUpdate(), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <RetentionTable
                            level="application"
                            rows={retention}
                            stats={retention_stats}
                            data={form.data}
                            setData={(next) => form.setData(next)}
                            errors={form.errors as Record<string, string>}
                        />
                        {/* Pinned to the bottom of the screen while there is something to save. */}
                        <div
                            className={cn(
                                form.isDirty &&
                                    'sticky bottom-0 -mx-4 -mb-4 rounded-b-lg border-t border-line bg-panel px-4 py-3',
                            )}
                        >
                            <Button
                                type="submit"
                                variant="primary"
                                disabled={form.processing}
                            >
                                {t('common.save')}
                            </Button>
                        </div>
                    </form>
                </Card>
            </PageBody>
            <LiveNotice live={live} />
        </>
    );
}
