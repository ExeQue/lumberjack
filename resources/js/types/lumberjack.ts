export type Period = '1h' | '24h' | '7d' | '30d';

export type ProjectHeader = {
    id: number;
    uuid: string;
    slug: string;
    name: string;
    can_administer: boolean;
    environments: string[];
};

export type DashboardFilters = {
    period: Period;
    environment: string | null;
};

export type SeriesPoint = { t: string; v: number };

export type IssueSummary = {
    id: string;
    number: number | null;
    reference: string | null;
    project: string | null;
    title: string;
    message: string;
    source: 'server' | 'browser' | 'log';
    status: 'open' | 'in_progress' | 'resolved' | 'ignored';
    regressed: boolean;
    escalating: boolean;
    returned: boolean;
    ignore_until: {
        at?: string;
        occurrences_left?: number;
        escalation?: boolean;
    } | null;
    new: boolean;
    location: string | null;
    occurrences: number;
    users: number;
    notes: number;
    labels: { id: number; name: string; color: string }[];
    first_seen_at: string;
    last_seen_at: string;
    last_release: string | null;
    assignee: { id: number; name: string } | null;
    repo_issue_url: string | null;
};

export type StackFrame = {
    file?: string;
    line?: number;
    column?: number;
    function?: string;
    class?: string;
    in_app?: boolean;
    code?: { start: number; lines: string[] };
};

export type Occurrence = {
    id: number;
    occurred_at: string;
    trace_id: string | null;
    environment: string | null;
    release: string | null;
    host: string | null;
    request: { method: string | null; route: string | null } | null;
    user_id: string | null;
    stacktrace?: StackFrame[];
    context: Record<string, unknown>;
};

export type IssueNote = {
    id: number;
    body: string;
    author: string | null;
    via_mcp: string | null;
    status: 'open' | 'in_progress' | 'resolved' | 'ignored' | null;
    created_at: string | null;
};

export type RouteStat = {
    method: string;
    route: string;
    requests: number;
    p50: number;
    p95: number;
    p99: number;
    error_rate: number;
    total_us: number;
    apdex: number;
    statuses: Record<'2xx' | '3xx' | '4xx' | '5xx', number>;
    series?: number[];
};

export type QueryStat = {
    sql_hash: string;
    sql: string;
    connection: string;
    calls: number;
    total_us: number;
    share: number;
    p95: number;
    max: number;
    most_from: string | null;
    location: string | null;
    per_trace: number;
    n_plus_one: boolean;
};

export type JobStat = {
    name: string;
    queue: string;
    runs: number;
    processed: number;
    failed: number;
    stuck: number;
    released: number;
    pending: number;
    retries: number;
    failure_rate: number;
    p50_duration: number | null;
    p95_duration: number | null;
    p95_wait: number | null;
};

export type JobRunRow = {
    id: number;
    uuid: string;
    trace_id?: string | null;
    name: string;
    status:
        | 'queued'
        | 'processing'
        | 'processed'
        | 'released'
        | 'failed'
        | 'stuck';
    attempt: number;
    max_tries: number | null;
    queue: string;
    queued_at: string | null;
    started_at: string | null;
    wait_us: number | null;
    duration_us: number | null;
    parameters: Record<string, unknown> | null;
    host: string | null;
    issue: string | null;
};

export type LogLine = {
    id: number;
    at: string;
    level:
        | 'debug'
        | 'info'
        | 'notice'
        | 'warning'
        | 'error'
        | 'critical'
        | 'alert'
        | 'emergency';
    channel: string | null;
    sent_channel: string | null;
    message: string;
    context: Record<string, unknown> | null;
    trace_id: string | null;
    environment: string | null;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

export type TeamShared = {
    uuid: string;
    name: string;
    role: 'owner' | 'admin' | 'member' | null;
    visiting?: boolean;
    can_administer: boolean;
    projects: { uuid: string; slug: string; name: string }[];
};

export type WebVitalStat = {
    route: string;
    views: number;
    lcp_us: number | null;
    inp_us: number | null;
    cls: number | null;
};

export type ReleaseStat = {
    release: string;
    first_seen_at: string;
    last_seen_at: string;
    requests: number;
    error_rate: number | null;
    p95: number | null;
    occurrences: number;
    new_issues: number;
    regressions: number;
    previous: {
        release: string;
        error_rate: number | null;
        p95: number | null;
    } | null;
    change: { error_rate: number | null; p95: number | null };
};

export type HealthSummary = {
    total: number;
    ok: number;
    recovering: number;
    degraded: number;
    down: number;
    stale: number;
    pending: number;
    /** Worst first. */
    checks: {
        uuid: string;
        name: string;
        state: HealthState;
        message: string | null;
    }[];
};

/**
 * Where a check stands as shown: stale when it stopped reporting, pending before its first result,
 * recovering when it was bad and has good results in a row, not yet enough to be ok.
 */
export type HealthState =
    | 'ok'
    | 'recovering'
    | 'degraded'
    | 'down'
    | 'stale'
    | 'pending';

export type HealthResultStatus = 'ok' | 'warning' | 'failed';

export type HealthCheckType = 'http' | 'ssl' | 'tcp' | 'dns' | 'heartbeat';

export type HealthHost = {
    host: string | null;
    state: HealthState;
    last_status: HealthResultStatus | null;
    last_message: string | null;
    last_result_at: string | null;
    duration_us: number | null;
    metrics: Record<string, number>;
};

export type HealthCheckRow = {
    uuid: string;
    source: 'reported' | 'monitored';
    type: HealthCheckType | null;
    name: string;
    custom_name: string | null;
    slug: string | null;
    description: string | null;
    environment: string | null;
    state: HealthState;
    muted: boolean;
    archived: boolean;
    degrade_after: number;
    /** Effective: never below degrade_after. */
    down_after: number;
    recover_after: number;
    thresholds_overridden: boolean;
    interval_minutes: number | null;
    config: Record<string, string | number> | null;
    last_result_at: string | null;
    last_status: HealthResultStatus | null;
    last_message: string | null;
    duration_us: number | null;
    metrics: Record<string, number>;
    uptime: number | null;
    /** What uptime is counted from, so it keeps counting between results. */
    uptime_live: {
        from: string;
        down_s: number;
        down_since: string | null;
    } | null;
    strip: { t: string; s: 'ok' | 'degraded' | 'down' | null }[];
    hosts: HealthHost[];
};

export type HealthCheckDetail = {
    uuid: string;
    ping_url: string | null;
    results: {
        id: number;
        host: string | null;
        status: HealthResultStatus;
        message: string | null;
        duration_us: number | null;
        metrics: Record<string, number>;
        checked_at: string;
    }[];
    outages: {
        id: number;
        status: 'degraded' | 'down';
        first_message: string | null;
        started_at: string;
        ended_at: string | null;
        duration_s: number;
    }[];
    latency_series: SeriesPoint[];
};

export type QueueBacklogSettings = {
    size: number | null;
    age_minutes: number | null;
    sustain_minutes: number;
};

/** A team's alert rule, or a project's own that overrides the team's of that type. */
export type AlertRule = {
    id: number;
    type: string;
    label: string;
    description: string;
    enabled: boolean;
    channels: number[];
    environments: string[];
    /** A queue backlog rule's thresholds; null for other rules. */
    settings: QueueBacklogSettings | null;
};

/** One type on a project: the team's rule, and the project's own when it customized it. */
export type ProjectAlertRule = {
    type: string;
    label: string;
    description: string;
    team: AlertRule | null;
    project: AlertRule | null;
    /** Channels the project adds to the team's rule while it uses that. */
    add_ons: number[];
};
