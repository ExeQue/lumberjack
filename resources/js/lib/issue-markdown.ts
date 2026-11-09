import type {
    IssueNote,
    IssueSummary,
    Occurrence,
    StackFrame,
} from '@/types/lumberjack';

type IssueMarkdownInput = {
    url: string;
    project: string;
    issue: IssueSummary & { hosts: string | null; releases: number };
    latest: Occurrence | null;
    notes: IssueNote[];
    history: {
        type: string;
        actor: string | null;
        data: Record<string, unknown> | null;
        at: string;
    }[];
    nameMentions: (body: string) => string;
};

/** Longer than any backtick run in the content, so the content cannot close its block. */
function fenced(content: string, language = ''): string {
    const longest = Math.max(
        2,
        ...(content.match(/`+/g) ?? []).map((run) => run.length),
    );
    const fence = '`'.repeat(longest + 1);

    return `${fence}${language}\n${content}\n${fence}`;
}

function facts(rows: [string, unknown][]): string {
    return rows
        .filter(
            ([, value]) =>
                value !== null && value !== undefined && value !== '',
        )
        .map(([label, value]) => `- **${label}:** ${String(value)}`)
        .join('\n');
}

function frameLine(frame: StackFrame, index: number): string {
    const name = [frame.class, frame.function].filter(Boolean).join('::');
    const place = [frame.file ?? '[unknown file]', frame.line]
        .filter((part) => part !== undefined)
        .join(':');

    return `#${index} ${frame.in_app ? '[app] ' : ''}${place}${name ? ` ${name}()` : ''}`;
}

function frameCode(frame: StackFrame): string | null {
    if (!frame.code || frame.code.lines.length === 0) {
        return null;
    }

    const { start, lines } = frame.code;

    return lines
        .map(
            (line, offset) =>
                `${start + offset === frame.line ? '>' : ' '} ${start + offset} | ${line}`,
        )
        .join('\n');
}

/**
 * An issue as markdown, with what an agent needs to work on it. The headings stay
 * English in every locale, as this is handed to tools rather than read on the page.
 */
export function issueMarkdown({
    url,
    project,
    issue,
    latest,
    notes,
    history,
    nameMentions,
}: IssueMarkdownInput): string {
    const sections = [
        `# ${issue.reference ? `${issue.reference}: ` : ''}${issue.title}`,
        issue.message && issue.message !== issue.title
            ? fenced(issue.message)
            : null,
        facts([
            ['URL', url],
            ['Project', project],
            ['Status', issue.status],
            ['Regressed', issue.regressed ? 'yes' : null],
            ['Escalating', issue.escalating ? 'yes' : null],
            ['Source', issue.source],
            ['Location', issue.location],
            ['Occurrences', issue.occurrences],
            ['Users affected', issue.users],
            ['First seen', issue.first_seen_at],
            ['Last seen', issue.last_seen_at],
            ['Latest release', issue.last_release],
            ['Releases', issue.releases],
            ['Hosts', issue.hosts],
            ['Assignee', issue.assignee?.name],
            ['Labels', issue.labels.map((label) => label.name).join(', ')],
            ['Repository issue', issue.repo_issue_url],
        ]),
    ];

    if (latest) {
        sections.push(
            '## Latest occurrence',
            facts([
                ['Occurred at', latest.occurred_at],
                ['Environment', latest.environment],
                ['Release', latest.release],
                ['Host', latest.host],
                [
                    'Request',
                    latest.request
                        ? [latest.request.method, latest.request.route]
                              .filter(Boolean)
                              .join(' ')
                        : null,
                ],
                ['User', latest.user_id],
                ['Trace', latest.trace_id],
            ]),
        );

        const frames = latest.stacktrace ?? [];

        if (frames.length > 0) {
            sections.push(
                '## Stacktrace',
                fenced(frames.map(frameLine).join('\n')),
            );

            const culprit = frames.find((frame) => frame.in_app) ?? frames[0];
            const code = frameCode(culprit);

            if (code) {
                sections.push(
                    `## Code at ${culprit.file ?? 'the top frame'}`,
                    fenced(code),
                );
            }
        }

        if (Object.keys(latest.context).length > 0) {
            sections.push(
                '## Context',
                fenced(JSON.stringify(latest.context, null, 2), 'json'),
            );
        }
    }

    if (notes.length > 0) {
        sections.push(
            '## Notes',
            ...notes.map(
                (note) =>
                    `### ${note.author ?? note.via_mcp ?? 'Unknown'}${note.created_at ? `, ${note.created_at}` : ''}\n\n${nameMentions(note.body)}`,
            ),
        );
    }

    const events = history.filter((entry) => entry.type !== 'note');

    if (events.length > 0) {
        sections.push(
            '## History',
            events
                .map((entry) => {
                    const hasData =
                        entry.data && Object.keys(entry.data).length > 0;

                    return `- ${entry.at}: ${entry.type}${entry.actor ? ` by ${entry.actor}` : ''}${hasData ? ` ${JSON.stringify(entry.data)}` : ''}`;
                })
                .join('\n'),
        );
    }

    return `${sections.filter(Boolean).join('\n\n')}\n`;
}
