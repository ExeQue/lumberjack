import { Head, useForm, usePage } from '@inertiajs/react';
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
import { useTeamLive, useFormSync } from '@/lib/use-live';
import { retention as teamRetention } from '@/routes/team';
import type {
    RetentionRow,
    RetentionStats,
    TeamShared,
} from '@/types/lumberjack';

export default function TeamRetention({
    retention,
    retention_stats,
}: {
    retention: RetentionRow[];
    /** Deferred: counted after the page shows. */
    retention_stats?: RetentionStats;
}) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const admin = team.can_administer;
    const form = useForm(retentionFormData(retention));
    useFormSync(form, retentionFormData(retention));
    const live = useTeamLive(['retention.changed'], ['retention'], {
        guard: true,
    });

    return (
        <>
            <Head title={t('team.retention.title')} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    { label: t('common.nav.retention') },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={t('team.retention.title')}
                    description={t('team.retention.description')}
                />
                <Card>
                    <form
                        className="flex flex-col gap-4 p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.transform(retentionPayload);
                            form.submit(teamRetention(), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <RetentionTable
                            level="team"
                            rows={retention}
                            stats={retention_stats}
                            data={form.data}
                            setData={(next) => form.setData(next)}
                            errors={form.errors as Record<string, string>}
                            readOnly={!admin}
                        />
                        {admin && (
                            <div>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={form.processing}
                                >
                                    {t('common.save')}
                                </Button>
                            </div>
                        )}
                    </form>
                </Card>
            </PageBody>
            <LiveNotice live={live} />
        </>
    );
}
