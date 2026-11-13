import { router } from '@inertiajs/react';
import { update as updateLabel } from '@/routes/activity/labels';

/** The names a team gave its activity groups (by group) and activities (by group:name). */
export type ActivityLabels = {
    groups: Record<string, string>;
    activities: Record<string, string>;
};

/** Names a group, or one activity of it; an empty label goes back to what the app records. */
export const saveActivityLabel = (
    project: string,
    group: string,
    name: string | null,
    label: string,
) =>
    router.put(
        updateLabel(project).url,
        { group, name, label: label || null },
        { preserveScroll: true, preserveState: true },
    );
