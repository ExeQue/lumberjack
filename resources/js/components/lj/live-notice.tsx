import { RefreshCw } from 'lucide-react';
import { Button } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import type { LiveReload } from '@/lib/use-live';

/** Says the page changed elsewhere while the user has unsaved edits, instead of loading it. */
export function LiveNotice({ live }: { live: LiveReload | LiveReload[] }) {
    const t = useT();
    const all = Array.isArray(live) ? live : [live];
    const changed = all.find((one) => one.changedElsewhere);

    if (changed === undefined) {
        return null;
    }

    return (
        <div
            role="status"
            className="fixed right-4 bottom-4 z-40 flex items-center gap-3 rounded-md border border-line bg-panel px-3 py-2 text-[13px] text-ink shadow-lg"
        >
            <span>{t('common.live_changed.message')}</span>
            <Button onClick={changed.reloadFresh}>
                <RefreshCw className="size-3.5" aria-hidden="true" />
                {t('common.live_changed.reload')}
            </Button>
        </div>
    );
}
