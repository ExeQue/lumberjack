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
    Input,
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
    /** Screens shown in turn; empty shows the one screen. */
    playlist: string[];
    rotate_seconds: number;
    hold_on_alert: boolean;
    theme: string;
    device: string | null;
    /** Windows of one computer share this. */
    computer: string;
    slot: number;
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
    const [editing, setEditing] = useState<Row | null>(null);
    const windows = (row: Row) =>
        displays.filter((d) => d.computer === row.computer);
    const tvUrl = <Mono className="text-amber">{bareUrl(tv.url)}</Mono>;
    const save = (
        display: Row,
        fields: Record<string, string | null | string[] | number | boolean>,
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
                                    {displays.flatMap((display, i) => [
                                        ...(windows(display).length > 1 &&
                                        displays[i - 1]?.computer !==
                                            display.computer
                                            ? [
                                                  <tr
                                                      key={`${display.computer}-computer`}
                                                  >
                                                      <th
                                                          colSpan={
                                                              can_manage ? 6 : 5
                                                          }
                                                          scope="rowgroup"
                                                          className={`${tdClass} bg-panel-2 text-left text-xs font-semibold text-ink-2`}
                                                      >
                                                          {t(
                                                              'screens.displays.computer',
                                                              {
                                                                  device:
                                                                      display.device ??
                                                                      t(
                                                                          'screens.pair.unknown_device',
                                                                      ),
                                                                  windows:
                                                                      windows(
                                                                          display,
                                                                      )
                                                                          .map(
                                                                              (
                                                                                  w,
                                                                              ) =>
                                                                                  t(
                                                                                      'screens.displays.window',
                                                                                      {
                                                                                          slot: w.slot,
                                                                                      },
                                                                                  ),
                                                                          )
                                                                          .join(
                                                                              ', ',
                                                                          ),
                                                              },
                                                          )}
                                                      </th>
                                                  </tr>,
                                              ]
                                            : []),
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
                                                <div className="mt-1 flex items-center gap-2 text-xs text-ink-3">
                                                    {display.playlist.length >
                                                        1 && (
                                                        <span>
                                                            {t(
                                                                'screens.displays.rotating',
                                                                {},
                                                                display.playlist
                                                                    .length,
                                                            )}
                                                        </span>
                                                    )}
                                                    {can_manage && (
                                                        <button
                                                            type="button"
                                                            className="text-amber hover:underline"
                                                            onClick={() =>
                                                                setEditing(
                                                                    display,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'screens.displays.rotate',
                                                            )}
                                                        </button>
                                                    )}
                                                </div>
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
                                                {[
                                                    display.device ??
                                                        t(
                                                            'screens.pair.unknown_device',
                                                        ),
                                                    ...(windows(display)
                                                        .length > 1 ||
                                                    display.slot > 1
                                                        ? [
                                                              t(
                                                                  'screens.displays.window',
                                                                  {
                                                                      slot: display.slot,
                                                                  },
                                                              ),
                                                          ]
                                                        : []),
                                                ].join(' · ')}
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
                                        </tr>,
                                    ])}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </PageBody>
            {editing && (
                <Playlist
                    display={editing}
                    screens={screens}
                    onClose={() => setEditing(null)}
                    onSave={(fields) => {
                        save(editing, fields);
                        setEditing(null);
                    }}
                />
            )}
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

/** Which screens a display shows in turn, in which order, and for how long each. */
function Playlist({
    display,
    screens,
    onClose,
    onSave,
}: {
    display: Row;
    screens: Props['screens'];
    onClose: () => void;
    onSave: (fields: {
        playlist: string[];
        rotate_seconds: number;
        hold_on_alert: boolean;
    }) => void;
}) {
    const t = useT();
    const [order, setOrder] = useState<string[]>(
        display.playlist.length > 0
            ? display.playlist
            : display.screen
              ? [display.screen]
              : [],
    );
    const [seconds, setSeconds] = useState(display.rotate_seconds);
    const [hold, setHold] = useState(display.hold_on_alert);
    const listed = [
        ...order.flatMap((uuid) => screens.filter((s) => s.uuid === uuid)),
        ...screens.filter((s) => !order.includes(s.uuid)),
    ];
    const move = (uuid: string, by: number) =>
        setOrder((all) => {
            const i = all.indexOf(uuid);
            const next = all.slice();
            next.splice(i, 1);
            next.splice(Math.max(0, Math.min(all.length - 1, i + by)), 0, uuid);

            return next;
        });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="border-line bg-panel sm:max-w-md">
                <DialogTitle>
                    {t('screens.displays.playlist_title', {
                        name: display.name,
                    })}
                </DialogTitle>
                <DialogDescription className="text-xs text-ink-2">
                    {t('screens.displays.playlist_hint')}
                </DialogDescription>
                <ul className="flex flex-col gap-1">
                    {listed.map((screen) => {
                        const picked = order.includes(screen.uuid);

                        return (
                            <li
                                key={screen.uuid}
                                className="flex items-center gap-2 rounded-md border border-line px-2.5 py-1.5"
                            >
                                <input
                                    id={`playlist-${screen.uuid}`}
                                    type="checkbox"
                                    className="size-4 accent-amber"
                                    checked={picked}
                                    onChange={(e) =>
                                        setOrder((all) =>
                                            e.target.checked
                                                ? [...all, screen.uuid]
                                                : all.filter(
                                                      (u) => u !== screen.uuid,
                                                  ),
                                        )
                                    }
                                />
                                <label
                                    htmlFor={`playlist-${screen.uuid}`}
                                    className="flex-1 text-sm"
                                >
                                    {screen.name}
                                </label>
                                {picked && (
                                    <>
                                        <Button
                                            variant="ghost"
                                            aria-label={t(
                                                'screens.displays.move_up',
                                                { name: screen.name },
                                            )}
                                            onClick={() =>
                                                move(screen.uuid, -1)
                                            }
                                        >
                                            <span aria-hidden="true">↑</span>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            aria-label={t(
                                                'screens.displays.move_down',
                                                { name: screen.name },
                                            )}
                                            onClick={() => move(screen.uuid, 1)}
                                        >
                                            <span aria-hidden="true">↓</span>
                                        </Button>
                                    </>
                                )}
                            </li>
                        );
                    })}
                </ul>
                <label className="flex items-center justify-between gap-2 text-sm">
                    {t('screens.displays.seconds')}
                    <Input
                        type="number"
                        min={10}
                        max={3600}
                        value={seconds}
                        onChange={(e) => setSeconds(Number(e.target.value))}
                        className="w-24"
                    />
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="size-4 accent-amber"
                        checked={hold}
                        onChange={(e) => setHold(e.target.checked)}
                    />
                    {t('screens.displays.hold')}
                </label>
                <div className="flex justify-end">
                    <Button
                        variant="primary"
                        onClick={() =>
                            onSave({
                                playlist: order,
                                rotate_seconds: seconds,
                                hold_on_alert: hold,
                            })
                        }
                    >
                        {t('screens.displays.save')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
