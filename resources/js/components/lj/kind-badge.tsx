import { Badge } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import type { RequestKind } from '@/types/lumberjack';

/** An Inertia or Livewire request told apart from an ordinary one. */
export function KindBadge({ kind }: { kind: RequestKind }) {
    const t = useT();

    return (
        <Badge tone="info" className="ml-2 align-middle font-sans">
            {t(`routes.kind.${kind}`)}
        </Badge>
    );
}
