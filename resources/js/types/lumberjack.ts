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
    can_administer: boolean;
    projects: { uuid: string; slug: string; name: string }[];
};
