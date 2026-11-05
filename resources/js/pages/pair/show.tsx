import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { LogMark } from '@/components/lj/logo';
import { bareUrl, withSlot } from '@/components/lj/screen/qr';
import { useNow } from '@/components/lj/relative';
import { Button, Field, Input, LinkButton, Select } from '@/components/lj/ui';
import { formatRelative } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { confirm, show } from '@/routes/pair';
import { displays } from '@/routes/team';
import { pair } from '@/routes';

type Props = {
    /** The code in the address, as typed; null on /pair itself. */
    code: string | null;
    /** The TV waiting for that code; null when no TV waits for it (any more). */
    pairing: {
        code: string;
        device: string | null;
        created_at: string | null;
        expires_at: string;
        /** Which window of its computer asks: /tv/{slot}. */
        slot: number;
        /** Where another window of that computer pairs; null past the last. */
        next_url: string | null;
    } | null;
    /**
     * Screens of the teams the user administers, each with the device this browser already
     * is in that team (the window then joins it), else null.
     */
    screens: {
        uuid: string;
        name: string;
        team: string | null;
        device: string | null;
    }[];
    /** Just paired: what it shows. */
    paired: {
        display: string;
        device: string;
        screen: string | null;
        slot: number;
        next_url: string | null;
    } | null;
};

/**
 * /pair on a phone or laptop: the TV's code (typed in, or from scanning its QR code),
 * which TV asks and since when, the screen it should show and its name.
 */
export default function Pair({ code, pairing, screens, paired }: Props) {
    const t = useT();
    const now = useNow();

    return (
        <div className="flex min-h-svh justify-center bg-ground px-5 py-6 text-ink">
            <Head title={t('screens.pair.title')} />
            <div className="flex w-full max-w-md flex-col gap-5">
                <Link
                    href={pair().url}
                    className="flex items-center gap-2 text-lg font-bold"
                >
                    <span
                        aria-hidden="true"
                        className="flex size-8 items-center justify-center rounded-lg bg-panel"
                    >
                        <LogMark size={20} />
                    </span>
                    Lumberjack
                </Link>

                {paired ? (
                    <Done paired={paired} />
                ) : pairing ? (
                    <ConfirmForm
                        code={code ?? pairing.code}
                        pairing={pairing}
                        screens={screens}
                        now={now}
                    />
                ) : (
                    <>
                        <h1 className="text-2xl leading-tight font-bold">
                            {t('screens.pair.title')}
                        </h1>
                        {code !== null && (
                            <p
                                role="alert"
                                className="rounded-xl border border-amber bg-amber/10 p-3 text-sm"
                            >
                                {t('screens.pair.unknown', { code })}
                            </p>
                        )}
                        <CodeForm />
                    </>
                )}
            </div>
        </div>
    );
}

function CodeForm() {
    const t = useT();
    const [value, setValue] = useState('');
    const normalized = value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();

    return (
        <form
            className="flex flex-col gap-3"
            onSubmit={(e) => {
                e.preventDefault();

                if (normalized.length === 6) {
                    router.visit(show(normalized).url);
                }
            }}
        >
            <Field
                label={t('screens.pair.enter')}
                htmlFor="pair-code"
                hint={t('screens.pair.enter_hint')}
            >
                <Input
                    id="pair-code"
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    maxLength={7}
                    autoComplete="off"
                    autoCapitalize="characters"
                    spellCheck={false}
                    placeholder="K7Q-M2X"
                    className="h-12 font-mono text-2xl tracking-widest uppercase"
                    autoFocus
                />
            </Field>
            {/* Grey, not a faded amber, until the code is complete: readable either way. */}
            <Button
                type="submit"
                variant={normalized.length === 6 ? 'primary' : 'default'}
                className="h-11 justify-center text-base disabled:text-ink-2 disabled:opacity-100"
                disabled={normalized.length !== 6}
            >
                {t('screens.pair.find')}
            </Button>
        </form>
    );
}

function ConfirmForm({
    code,
    pairing,
    screens,
    now,
}: {
    code: string;
    pairing: NonNullable<Props['pairing']>;
    screens: Props['screens'];
    now: number;
}) {
    const t = useT();
    const form = useForm({
        screen: screens[0]?.uuid ?? '',
        name: t('screens.pair.name_default'),
    });
    const errors = form.errors as Record<string, string | undefined>;
    // Another window of a device the team has: only its screen is asked.
    const joining =
        screens.find((s) => s.uuid === form.data.screen)?.device ?? null;

    return (
        <form
            className="flex flex-col gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) => ({
                    ...data,
                    name: joining ? '' : data.name,
                }));
                form.submit(confirm(code.replace('-', '')));
            }}
        >
            <h1 className="text-2xl leading-tight font-bold">
                {joining
                    ? t('screens.pair.join', {
                          slot: pairing.slot,
                          device: joining,
                      })
                    : t('screens.pair.question')}
            </h1>
            {joining && (
                <p className="text-sm text-ink-2">
                    {t('screens.pair.join_body', {
                        slot: pairing.slot,
                        device: joining,
                    })}
                </p>
            )}
            <div className="flex flex-col gap-1.5 rounded-xl border border-line bg-panel px-4 py-3.5">
                <span className="flex items-center justify-between gap-2">
                    <span className="font-mono text-3xl font-bold tracking-widest">
                        {pairing.code}
                    </span>
                    {pairing.slot > 1 && (
                        <span className="rounded-full border border-line-strong px-2.5 py-0.5 text-xs text-ink-2">
                            {t('screens.tv.window', { slot: pairing.slot })}
                        </span>
                    )}
                </span>
                <span className="text-sm text-ink-2">
                    {t('screens.pair.device', {
                        device:
                            pairing.device ?? t('screens.pair.unknown_device'),
                        age: formatRelative(pairing.created_at, now),
                    })}
                </span>
                <span className="text-xs text-ink-3">
                    {t('screens.pair.check')}
                </span>
            </div>
            {errors.code && (
                <p role="alert" className="text-sm text-err">
                    {errors.code}
                </p>
            )}
            {screens.length === 0 ? (
                <p role="alert" className="text-sm text-ink-2">
                    {t('screens.pair.no_screens')}
                </p>
            ) : (
                <>
                    <Field
                        label={
                            joining
                                ? t('screens.pair.screen_window', {
                                      slot: pairing.slot,
                                  })
                                : t('screens.pair.screen')
                        }
                        htmlFor="pair-screen"
                        error={errors.screen}
                    >
                        <Select
                            id="pair-screen"
                            value={form.data.screen}
                            onChange={(e) =>
                                form.setData('screen', e.target.value)
                            }
                            className="h-11 text-base"
                        >
                            {screens.map((s) => (
                                <option key={s.uuid} value={s.uuid}>
                                    {t('screens.pair.screen_option', {
                                        screen: s.name,
                                        team: s.team ?? '',
                                    })}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    {!joining && (
                        <Field
                            label={t('screens.pair.name')}
                            htmlFor="pair-name"
                            hint={t('screens.pair.name_hint')}
                            error={errors.name}
                        >
                            <Input
                                id="pair-name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                maxLength={60}
                                required
                                className="h-11 text-base"
                            />
                        </Field>
                    )}
                    <Button
                        type="submit"
                        variant="primary"
                        className="h-12 justify-center text-base font-bold"
                        disabled={form.processing}
                    >
                        {t('screens.pair.confirm')}
                    </Button>
                </>
            )}
            <span className="text-center text-xs text-ink-3">
                {t('screens.pair.expires', {
                    time: formatRelative(pairing.expires_at, now),
                })}
            </span>
            <NextWindow
                url={pairing.next_url}
                device={joining ?? form.data.name}
            />
        </form>
    );
}

function Done({ paired }: { paired: NonNullable<Props['paired']> }) {
    const t = useT();

    return (
        <div role="status" className="flex flex-col items-start gap-4">
            <span
                aria-hidden="true"
                className="flex size-13 items-center justify-center rounded-full border-2 border-ok bg-ok/15 text-ok"
            >
                <Check className="size-7" />
            </span>
            <h1 className="text-2xl font-bold">
                {t('screens.pair.done', {
                    display: paired.display,
                    screen: paired.screen ?? '-',
                })}
            </h1>
            <p className="text-sm text-ink-2">{t('screens.pair.done_body')}</p>
            <NextWindow url={paired.next_url} device={paired.device} />
            <div className="flex flex-wrap gap-2">
                <LinkButton href={pair().url}>
                    {t('screens.pair.another')}
                </LinkButton>
                <LinkButton href={displays().url} variant="primary">
                    {t('screens.pair.displays')}
                </LinkButton>
            </div>
        </div>
    );
}

/** One device, several screens: the address of its next window. */
function NextWindow({ url, device }: { url: string | null; device: string }) {
    const t = useT();

    if (url === null) {
        return null;
    }

    return (
        <p className="rounded-xl border border-line bg-panel px-3.5 py-3 text-xs text-ink-2">
            {withSlot(
                (r) => t('screens.pair.another_window', { ...r, device }),
                'url',
                <span className="font-mono text-amber">{bareUrl(url)}</span>,
            )}
        </p>
    );
}
