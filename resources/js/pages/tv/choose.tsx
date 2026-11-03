import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useRef } from 'react';
import type { KeyboardEvent } from 'react';
import { LogMark } from '@/components/lj/logo';
import { bareUrl, withSlot } from '@/components/lj/screen/qr';
import { useT } from '@/lib/i18n';

type Props = {
    /** "Office PC" */
    device: string;
    /** The paired windows of this browser, in window order. */
    windows: {
        slot: number;
        /** "Window 2", or its own name. */
        label: string;
        /** The names of the screens it shows, in turn when several. */
        screens: string[];
        url: string;
    }[];
    /** The next free window, which pairs with a new code; null once all are paired. */
    add: { slot: number; url: string } | null;
};

/**
 * /tv on a paired device, the landing page: which window this one shows, in big buttons
 * for a remote (arrow keys and Enter), or another window to add. It never forwards by
 * itself; each window lives at its own address, /tv/{slot}, its start page.
 */
export default function TvChoose({ device, windows, add }: Props) {
    const t = useT();
    const list = useRef<HTMLUListElement>(null);

    // Arrow keys move between the buttons, as a remote's do; Enter follows the link.
    const move = (e: KeyboardEvent<HTMLUListElement>) => {
        const by = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[
            e.key
        ];

        if (by === undefined || list.current === null) {
            return;
        }

        const links = Array.from(list.current.querySelectorAll('a'));
        const at = links.indexOf(document.activeElement as HTMLAnchorElement);
        links[(at + by + links.length) % links.length]?.focus();
        e.preventDefault();
    };

    const button =
        'flex h-full flex-col gap-[0.6vw] rounded-[1vw] border-[0.2vw] border-(--s-line-strong) bg-(--s-panel) px-[2vw] py-[1.6vw] outline-none focus:border-(--s-amber) focus:bg-(--s-panel-2)';

    return (
        <div
            className="lj-screen fixed inset-0 flex flex-col overflow-hidden px-[5vw] py-[3.3vw]"
            data-theme="dark"
        >
            <Head title={t('screens.tv.choose_title')} />
            <div className="flex items-center gap-[0.8vw]">
                <span
                    aria-hidden="true"
                    className="flex size-[2.3vw] items-center justify-center rounded-[0.5vw] bg-(--s-panel) [&_svg]:size-[1.5vw]"
                >
                    <LogMark />
                </span>
                <span className="text-[1.6vw] font-bold">Lumberjack</span>
            </div>
            <main className="flex flex-1 flex-col justify-center gap-[2vw]">
                <div className="flex flex-col gap-[0.6vw]">
                    <h1 className="text-[2.9vw] leading-tight font-bold">
                        {t('screens.tv.choose_title')}
                    </h1>
                    <p className="text-[1.4vw] text-(--s-ink-2)">
                        {t('screens.tv.choose_hint', { device })}
                    </p>
                </div>
                <ul
                    ref={list}
                    onKeyDown={move}
                    className="grid grid-cols-3 gap-[1.5vw]"
                >
                    {windows.map((w, i) => (
                        <li key={w.slot}>
                            <a
                                href={w.url}
                                autoFocus={i === 0}
                                className={button}
                            >
                                <span className="text-[2.2vw] font-bold">
                                    {w.label}
                                </span>
                                <span className="text-[1.4vw] text-(--s-ink-2)">
                                    {w.screens.length > 0
                                        ? w.screens.join(', ')
                                        : t('screens.tv.no_screen')}
                                </span>
                                <span className="font-mono text-[1.15vw] text-(--s-amber-soft)">
                                    {bareUrl(w.url)}
                                </span>
                            </a>
                        </li>
                    ))}
                    {add && (
                        <li>
                            <a
                                href={add.url}
                                className={`${button} border-dashed`}
                            >
                                <span className="flex items-center gap-[0.6vw] text-[2.2vw] font-bold">
                                    <Plus
                                        aria-hidden="true"
                                        className="size-[2.2vw]"
                                    />
                                    {t('screens.tv.add_window')}
                                </span>
                                <span className="text-[1.4vw] text-(--s-ink-2)">
                                    {t('screens.tv.add_window_hint', {
                                        slot: add.slot,
                                    })}
                                </span>
                                <span className="font-mono text-[1.15vw] text-(--s-amber-soft)">
                                    {bareUrl(add.url)}
                                </span>
                            </a>
                        </li>
                    )}
                </ul>
            </main>
            <p className="text-[1.05vw] text-(--s-ink-3)">
                {withSlot(
                    (r) => t('screens.tv.choose_start_page', r),
                    'url',
                    <span className="font-mono">
                        {bareUrl(windows[0]?.url ?? '')}
                    </span>,
                )}
            </p>
        </div>
    );
}
