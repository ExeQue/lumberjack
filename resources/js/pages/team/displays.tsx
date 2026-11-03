import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { Relative } from '@/components/lj/relative';
import { bareUrl, withSlot } from '@/components/lj/screen/qr';
import {
    TvInstructions,
    type TvInstructionsProps,
} from '@/components/lj/screen/tv-instructions';
import { TopBar } from '@/components/lj/top-bar';
import {
    Button,
    Card,
    EmptyState,
    Mono,
    PageTitle,
    Select,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import { destroy, update } from '@/routes/displays';
import type { TeamShared } from '@/types/lumberjack';

type Row = {
    uuid: string;
    name: string;
    screen: string | null;
    theme: string;
    device: string | null;
    online: boolean;
    last_seen_at: string | null;
};

type Props = {
    displays: Row[];
    screens: { uuid: string; name: string }[];
    themes: string[];
    can_manage: boolean;
    tv: TvInstructionsProps;
};

/**
 * Team settings > Displays: the TVs paired to the team, what each shows, whether it is
 * online. A change reaches the TV at once.
 */
export default function Displays({
    displays,
    screens,
    themes,
    can_manage,
    tv,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [pairing, setPairing] = useState(false);
    const tvUrl = <Mono className="text-amber">{bareUrl(tv.url)}</Mono>;
    const save = (
        display: Row,
        fields: Partial<Record<string, string | null>>,
    ) => router.put(update(display.uuid).url, fields, { preserveScroll: true });

    return (
        <>
            <Head title={t('screens.displays.title')} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    { label: t('common.nav.displays') },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={t('screens.displays.title')}
                    description={withSlot(
                        (r) => t('screens.displays.description', r),
                        'url',
                        tvUrl,
                    )}
                    actions={
                        can_manage && (
                            <Button
                                variant="primary"
                                onClick={() => setPairing(true)}
                            >
                                {t('screens.displays.pair')}
                            </Button>
                        )
                    }
                />
                {!can_manage && (
                    <p className="text-[13px] text-ink-3">
                        {t('screens.displays.read_only')}
                    </p>
                )}
                <Card>
                    {displays.length === 0 ? (
                        <EmptyState title={t('screens.displays.empty_title')}>
                            {withSlot(
                                (r) => t('screens.displays.empty_body', r),
                                'url',
                                tvUrl,
                            )}
                        </EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('screens.displays.name')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.displays.shows')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.displays.theme')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.displays.device')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.displays.status')}
                                        </th>
                                        {can_manage && (
                                            <th className={thClass}>
                                                <span className="sr-only">
                                                    {t(
                                                        'screens.displays.actions',
                                                    )}
                                                </span>
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {displays.map((display) => (
                                        <tr key={display.uuid}>
                                            <td
                                                className={`${tdClass} font-medium`}
                                            >
                                                {display.name}
                                            </td>
                                            <td className={tdClass}>
                                                <Select
                                                    aria-label={`${t('screens.displays.shows')}: ${display.name}`}
                                                    value={display.screen ?? ''}
                                                    disabled={!can_manage}
                                                    onChange={(e) =>
                                                        save(display, {
                                                            screen:
                                                                e.target
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                >
                                                    <option value="">
                                                        {t(
                                                            'screens.displays.no_screen',
                                                        )}
                                                    </option>
                                                    {screens.map((s) => (
                                                        <option
                                                            key={s.uuid}
                                                            value={s.uuid}
                                                        >
                                                            {s.name}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </td>
                                            <td className={tdClass}>
                                                <Select
                                                    aria-label={`${t('screens.displays.theme')}: ${display.name}`}
                                                    value={display.theme}
                                                    disabled={!can_manage}
                                                    onChange={(e) =>
                                                        save(display, {
                                                            theme: e.target
                                                                .value,
                                                        })
                                                    }
                                                >
                                                    {themes.map((theme) => (
                                                        <option
                                                            key={theme}
                                                            value={theme}
                                                        >
                                                            {t(
                                                                `screens.display_theme.${theme}`,
                                                            )}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </td>
                                            <td
                                                className={`${tdClass} text-ink-2`}
                                            >
                                                {display.device ??
                                                    t(
                                                        'screens.pair.unknown_device',
                                                    )}
                                            </td>
                                            <td className={tdClass}>
                                                <Status display={display} />
                                            </td>
                                            {can_manage && (
                                                <td
                                                    className={`${tdClass} text-right`}
                                                >
                                                    <ConfirmButton
                                                        question={t(
                                                            'screens.displays.remove_question',
                                                            {
                                                                name: display.name,
                                                            },
                                                        )}
                                                        confirmLabel={t(
                                                            'screens.displays.remove',
                                                        )}
                                                        onConfirm={() =>
                                                            router.delete(
                                                                destroy(
                                                                    display.uuid,
                                                                ).url,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'screens.displays.remove',
                                                        )}
                                                    </ConfirmButton>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </PageBody>
            <Dialog open={pairing} onOpenChange={setPairing}>
                <DialogContent className="border-line bg-panel sm:max-w-xl">
                    <DialogTitle>{t('screens.displays.pair')}</DialogTitle>
                    <DialogDescription className="sr-only">
                        {t('screens.open_tv.step_pick')}
                    </DialogDescription>
                    <TvInstructions {...tv} />
                </DialogContent>
            </Dialog>
        </>
    );
}

function Status({ display }: { display: Row }) {
    const t = useT();

    if (display.online) {
        return (
            <span className="flex items-center gap-2 text-ok">
                <span
                    aria-hidden="true"
                    className="size-2 rounded-full bg-ok"
                />
                {t('screens.displays.online')}
            </span>
        );
    }

    return (
        <span className="flex items-center gap-2 text-ink-3">
            <span aria-hidden="true" className="size-2 rounded-full bg-ink-3" />
            {display.last_seen_at === null ? (
                t('screens.displays.never_seen')
            ) : (
                <span>
                    {withSlot(
                        (r) => t('screens.displays.last_seen', r),
                        'time',
                        <Relative iso={display.last_seen_at} />,
                    )}
                </span>
            )}
        </span>
    );
}
