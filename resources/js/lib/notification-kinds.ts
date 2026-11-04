import {
    Activity,
    AtSign,
    Bug,
    CalendarClock,
    Eye,
    Layers,
    Rocket,
    Server,
    UserCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/** The notification kinds, in the order of the settings, each with its icon. */
export const NOTIFICATION_KINDS: Record<string, LucideIcon> = {
    watching: Eye,
    mentions: AtSign,
    assignments: UserCheck,
    new_issues: Bug,
    health: Activity,
    queue_backlogs: Layers,
    scheduled_tasks: CalendarClock,
    agents: Server,
    releases: Rocket,
};

export type CenterNotification = {
    id: string;
    kind: string;
    title: string;
    body: string;
    url: string;
    project: string | null;
    team: string | null;
    read: boolean;
    created_at: string | null;
};

/** "99+" past that: the badge stays one size. */
export const unreadLabel = (count: number) =>
    count > 99 ? '99+' : String(count);
