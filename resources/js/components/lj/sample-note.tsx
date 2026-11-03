import { Badge } from '@/components/lj/ui';
import { formatPercent } from '@/lib/format';
import { useT } from '@/lib/i18n';

/**
 * Marks figures that are estimated because the SDK sampled runs: the lowest rate in the
 * window. Nothing when every run was kept.
 */
export function SampleNote({ rate }: { rate: number | null | undefined }) {
    const t = useT();

    if (rate === null || rate === undefined) {
        return null;
    }

    const share = formatPercent(rate, 1);

    return (
        <Badge
            tone="neutral"
            title={t('common.sampling.help', { rate: share })}
        >
            {t('common.sampling.estimated', { rate: share })}
        </Badge>
    );
}
