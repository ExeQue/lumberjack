import { Head } from '@inertiajs/react';
import { LogMark } from '@/components/lj/logo';
import { useT } from '@/lib/i18n';

/** /s/{key} for a key that no longer works. */
export default function Unshared() {
    const t = useT();

    return (
        <div
            className="lj-screen fixed inset-0 flex flex-col items-center justify-center gap-[1vw] text-center"
            data-theme="dark"
        >
            <Head title={t('screens.kiosk.unshared')} />
            <span
                aria-hidden="true"
                className="flex size-[4vw] min-h-10 min-w-10 items-center justify-center rounded-[0.8vw] bg-(--s-panel) [&_svg]:size-[2.6vw]"
            >
                <LogMark />
            </span>
            <h1 className="text-[max(1.5rem,2.4vw)] font-bold">
                {t('screens.kiosk.unshared')}
            </h1>
            <p className="text-[max(1rem,1.3vw)] text-(--s-ink-2)">
                {t('screens.kiosk.unshared_body')}
            </p>
        </div>
    );
}
