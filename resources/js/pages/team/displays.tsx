import { Head, router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { Relative, useNow } from '@/components/lj/relative';
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
import {
    destroy as destroyDevice,
    reload as reloadDevice,
    update as updateDevice,
} from '@/routes/devices';
import { destroy, identify, reload, update } from '@/routes/displays';
import type { TeamShared } from '@/types/lumberjack';

/** One window of a device, /tv/{slot}. */
type Row = {
    uuid: string;
    slot: number;
    /** Its own name, besides its window number. */
    name: string | null;
    /** "Window 2", or its own name. */
    label: string;
    screen: string | null;
    /** Screens shown in turn; empty shows the one screen. */
    playlist: string[];
    rotate_seconds: number;
    hold_on_alert: boolean;
    theme: string;
    /** Degrees the picture is turned clockwise. */
    rotation: number;
    viewport: { w: number; h: number } | null;
    online: boolean;
    last_seen_at: string | null;
};

type DeviceRow = {
    uuid: string;
    name: string;
    browser: string | null;
    online: boolean;
    last_seen_at: string | null;
    /** Where its next window pairs; null once all are. */
    next_url: string | null;
    windows: Row[];
};

/** What a window says when it reports in, on the team's displays channel. */
type Report = {
    uuid: string;
    viewport: { w: number; h: number } | null;
    last_seen_at: string | null;
};

/** Heard from this recently, a window counts as online (Display::ONLINE_SECONDS). */
const ONLINE_MS = 180_000;

type Props = {
    devices: DeviceRow[];
    channel: string;
    screens: { uuid: string; name: string }[];
    themes: string[];
    can_manage: boolean;
    tv: TvInstructionsProps;
};

/**
 * Team settings > Displays: the devices paired to the team, one card each, with their
 * windows: what each shows, whether it is online. A change reaches the TV at once.
 */
export default function Displays({
    devices: listed,
    channel,
    screens,
    themes,
    can_manage,
    tv,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [pairing, setPairing] = useState(false);
    const now = useNow();
    // Sizes and last seen as the windows report them, newer than the page.
    const [reports, setReports] = useState<Record<string, Report>>({});
    useEcho<Report>(channel, '.display.updated', (report) =>
        setReports((all) => ({ ...all, [report.uuid]: report })),
    );
    // Online is worked out here, so a TV that goes silent turns offline on its own.
    const isOnline = (iso: string | null) =>
        iso !== null && now - Date.parse(iso) < ONLINE_MS;
    const devices = listed.map((device) => {
        const windows = device.windows.map((w) => {
            const report = reports[w.uuid];
            const seen =
                report?.last_seen_at &&
                (w.last_seen_at === null ||
                    Date.parse(report.last_seen_at) >
                        Date.parse(w.last_seen_at))
                    ? report
                    : null;
            const last = seen?.last_seen_at ?? w.last_seen_at;

            return {
                ...w,
                viewport: seen ? seen.viewport : w.viewport,
                last_seen_at: last,
                online: isOnline(last),
            };
        });
        const last = windows.reduce<string | null>(
            (max, w) =>
                w.last_seen_at !== null &&
                (max === null || Date.parse(w.last_seen_at) > Date.parse(max))
                    ? w.last_seen_at
                    : max,
            device.last_seen_at,
        );

        return {
            ...device,
            windows,
            last_seen_at: last,
            online: windows.some((w) => w.online),
        };
    });
    const [editing, setEditing] = useState<{
        device: DeviceRow;
        window: Row;
    } | null>(null);
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
                {devices.length === 0 ? (
                    <Card>
                        <EmptyState title={t('screens.displays.empty_title')}>
                            {withSlot(
                                (r) => t('screens.displays.empty_body', r),
                                'url',
                                tvUrl,
                            )}
                        </EmptyState>
                    </Card>
                ) : (
                    devices.map((device) => (
                        <DeviceCard
                            key={device.uuid}
                            device={device}
                            screens={screens}
                            themes={themes}
                            canManage={can_manage}
                            onSave={save}
                            onPlaylist={(window) =>
                                setEditing({ device, window })
                            }
                        />
                    ))
                )}
            </PageBody>
            {editing && (
                <Playlist
                    display={editing.window}
                    title={`${editing.device.name}, ${editing.window.label}`}
                    screens={screens}
                    onClose={() => setEditing(null)}
                    onSave={(fields) => {
                        save(editing.window, fields);
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

/** A device: its name, browser and status, reload or remove it all, and its windows. */
function DeviceCard({
    device,
    screens,
    themes,
    canManage,
    onSave,
    onPlaylist,
}: {
    device: DeviceRow;
    screens: Props['screens'];
    themes: string[];
    canManage: boolean;
    onSave: (
        display: Row,
        fields: Record<string, string | null | string[] | number | boolean>,
    ) => void;
    onPlaylist: (window: Row) => void;
}) {
    const t = useT();
    const headingId = `device-${device.uuid}`;
    const rename = (name: string) => {
        if (name.trim() !== '' && name.trim() !== device.name) {
            router.put(
                updateDevice(device.uuid).url,
                { name: name.trim() },
                { preserveScroll: true },
            );
        }
    };

    return (
        <Card aria-labelledby={headingId}>
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <h2
                        id={headingId}
                        className={
                            canManage ? 'sr-only' : 'text-[13px] font-semibold'
                        }
                    >
                        {device.name}
                    </h2>
                    {canManage && (
                        <Input
                            key={device.name}
                            aria-label={t('screens.displays.device_name', {
                                name: device.name,
                            })}
                            defaultValue={device.name}
                            maxLength={60}
                            className="h-8 w-64 font-semibold"
                            onBlur={(e) => rename(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.currentTarget.blur();
                                }
                            }}
                        />
                    )}
                    <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-3">
                        <span>
                            {device.browser ?? t('screens.pair.unknown_device')}
                        </span>
                        <span>
                            {t(
                                'screens.displays.windows',
                                {},
                                device.windows.length,
                            )}
                        </span>
                        <Status
                            online={device.online}
                            lastSeen={device.last_seen_at}
                        />
                    </span>
                </div>
                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            title={t('screens.displays.reload_device_hint')}
                            onClick={() =>
                                router.post(
                                    reloadDevice(device.uuid).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('screens.displays.reload_device')}
                        </Button>
                        <ConfirmButton
                            question={t(
                                'screens.displays.remove_device_question',
                                { name: device.name },
                            )}
                            confirmLabel={t('screens.displays.remove_device')}
                            onConfirm={() =>
                                router.delete(destroyDevice(device.uuid).url, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            {t('screens.displays.remove_device')}
                        </ConfirmButton>
                    </div>
                )}
            </div>
            <div className="overflow-x-auto">
                <table className={tableClass}>
                    <thead>
                        <tr>
                            <th className={thClass}>
                                {t('screens.displays.window_col')}
                            </th>
                            <th className={thClass}>
                                {t('screens.displays.shows')}
                            </th>
                            <th className={thClass}>
                                {t('screens.displays.seconds_col')}
                            </th>
                            <th className={thClass}>
                                {t('screens.displays.hold_col')}
                            </th>
                            <th className={thClass}>
                                {t('screens.displays.theme')}
                            </th>
                            <th
                                className={thClass}
                                title={t('screens.displays.rotation_hint')}
                            >
                                {t('screens.displays.rotation')}
                            </th>
                            <th className={thClass}>
                                {t('screens.displays.viewport')}
                            </th>
                            <th className={thClass}>
                                {t('screens.displays.status')}
                            </th>
                            {canManage && (
                                <th className={thClass}>
                                    <span className="sr-only">
                                        {t('screens.displays.actions')}
                                    </span>
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        {device.windows.map((display) => (
                            <tr key={display.uuid}>
                                <td
                                    className={`${tdClass} font-medium whitespace-nowrap`}
                                >
                                    {display.label}
                                </td>
                                <td className={tdClass}>
                                    <Select
                                        aria-label={`${t('screens.displays.shows')}: ${device.name}, ${display.label}`}
                                        value={display.screen ?? ''}
                                        disabled={!canManage}
                                        onChange={(e) =>
                                            onSave(display, {
                                                screen: e.target.value || null,
                                            })
                                        }
                                    >
                                        <option value="">
                                            {t('screens.displays.no_screen')}
                                        </option>
                                        {screens.map((s) => (
                                            <option key={s.uuid} value={s.uuid}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </Select>
                                    <div className="mt-1 flex items-center gap-2 text-xs text-ink-3">
                                        {display.playlist.length > 1 && (
                                            <span>
                                                {t(
                                                    'screens.displays.rotating',
                                                    {},
                                                    display.playlist.length,
                                                )}
                                            </span>
                                        )}
                                        {canManage && (
                                            <button
                                                type="button"
                                                className="text-amber hover:underline"
                                                onClick={() =>
                                                    onPlaylist(display)
                                                }
                                            >
                                                {t('screens.displays.rotate')}
                                            </button>
                                        )}
                                    </div>
                                </td>
                                <td
                                    className={`${tdClass} font-mono text-ink-2 tabular-nums`}
                                >
                                    {display.rotate_seconds}
                                </td>
                                <td className={`${tdClass} text-ink-2`}>
                                    {display.hold_on_alert
                                        ? t('screens.displays.yes')
                                        : t('screens.displays.no')}
                                </td>
                                <td className={tdClass}>
                                    <Select
                                        aria-label={`${t('screens.displays.theme')}: ${device.name}, ${display.label}`}
                                        value={display.theme}
                                        disabled={!canManage}
                                        onChange={(e) =>
                                            onSave(display, {
                                                theme: e.target.value,
                                            })
                                        }
                                    >
                                        {themes.map((theme) => (
                                            <option key={theme} value={theme}>
                                                {t(
                                                    `screens.display_theme.${theme}`,
                                                )}
                                            </option>
                                        ))}
                                    </Select>
                                </td>
                                <td className={tdClass}>
                                    <Select
                                        aria-label={`${t('screens.displays.rotation')}: ${device.name}, ${display.label}`}
                                        value={String(display.rotation)}
                                        disabled={!canManage}
                                        onChange={(e) =>
                                            onSave(display, {
                                                rotation: Number(
                                                    e.target.value,
                                                ),
                                            })
                                        }
                                    >
                                        {[0, 90, 180, 270].map((degrees) => (
                                            <option
                                                key={degrees}
                                                value={degrees}
                                            >
                                                {t(
                                                    `screens.displays.rotations.${degrees}`,
                                                )}
                                            </option>
                                        ))}
                                    </Select>
                                </td>
                                <td
                                    className={`${tdClass} font-mono whitespace-nowrap text-ink-2 tabular-nums`}
                                >
                                    {display.viewport
                                        ? `${display.viewport.w} × ${display.viewport.h}`
                                        : t(
                                              'screens.displays.unknown_viewport',
                                          )}
                                </td>
                                <td className={tdClass}>
                                    <Status
                                        online={display.online}
                                        lastSeen={display.last_seen_at}
                                    />
                                </td>
                                {canManage && (
                                    <td
                                        className={`${tdClass} text-right whitespace-nowrap`}
                                    >
                                        <Button
                                            variant="ghost"
                                            title={t(
                                                'screens.displays.identify_hint',
                                            )}
                                            aria-label={`${t('screens.displays.identify')}: ${display.label}`}
                                            onClick={() =>
                                                router.post(
                                                    identify(display.uuid).url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('screens.displays.identify')}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            title={t(
                                                'screens.displays.reload_hint',
                                            )}
                                            aria-label={`${t('screens.displays.reload')}: ${display.label}`}
                                            onClick={() =>
                                                router.post(
                                                    reload(display.uuid).url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('screens.displays.reload')}
                                        </Button>
                                        <ConfirmButton
                                            question={t(
                                                'screens.displays.remove_question',
                                                {
                                                    name: `${device.name}, ${display.label}`,
                                                },
                                            )}
                                            confirmLabel={t(
                                                'screens.displays.remove',
                                            )}
                                            onConfirm={() =>
                                                router.delete(
                                                    destroy(display.uuid).url,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('screens.displays.remove')}
                                        </ConfirmButton>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {canManage && device.next_url && (
                <p className="px-4 py-3 text-xs text-ink-3">
                    {withSlot(
                        (r) => t('screens.displays.add_window', r),
                        'url',
                        <Mono className="text-amber">
                            {bareUrl(device.next_url)}
                        </Mono>,
                    )}
                </p>
            )}
        </Card>
    );
}

function Status({
    online,
    lastSeen,
}: {
    online: boolean;
    lastSeen: string | null;
}) {
    const t = useT();

    if (online) {
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
            {lastSeen === null ? (
                t('screens.displays.never_seen')
            ) : (
                <span>
                    {withSlot(
                        (r) => t('screens.displays.last_seen', r),
                        'time',
                        <Relative iso={lastSeen} />,
                    )}
                </span>
            )}
        </span>
    );
}

/** Which screens a display shows in turn, in which order, and for how long each. */
function Playlist({
    display,
    title,
    screens,
    onClose,
    onSave,
}: {
    display: Row;
    title: string;
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
                        name: title,
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
