<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Enums;

/**
 * The key figures on top of the GitHub page, each of which a connection shows or hides.
 */
enum GitHubFigure: string
{
    case OpenPullRequests = 'open_pull_requests';
    case WaitingForReview = 'waiting_for_review';
    case Merged = 'merged';
    case LeadTime = 'lead_time';
    case Commits = 'commits';
    case Authors = 'authors';
    case Ci = 'ci';
    case FailedRuns = 'failed_runs';
    case LatestRelease = 'latest_release';
    case OpenIssues = 'open_issues';
    case IssuesOpened = 'issues_opened';
    case IssuesClosed = 'issues_closed';
    case OpenBugs = 'open_bugs';
    case Stars = 'stars';
    case Forks = 'forks';
    case Watchers = 'watchers';
    case DependabotAlerts = 'dependabot_alerts';
    case CodeScanningAlerts = 'code_scanning_alerts';
    case SecretScanningAlerts = 'secret_scanning_alerts';

    /** The heading it is ticked under on the Connection tab. */
    public function group(): string
    {
        return match ($this) {
            self::OpenPullRequests, self::WaitingForReview, self::Merged, self::LeadTime => 'pull_requests',
            self::Commits, self::Authors, self::Ci, self::FailedRuns, self::LatestRelease => 'code',
            self::OpenIssues, self::IssuesOpened, self::IssuesClosed, self::OpenBugs => 'issues',
            self::Stars, self::Forks, self::Watchers => 'community',
            self::DependabotAlerts, self::CodeScanningAlerts, self::SecretScanningAlerts => 'security',
        };
    }

    /** The optional permission it needs, if any; without it, it is never shown. */
    public function permission(): ?GitHubPermission
    {
        return match ($this) {
            self::IssuesOpened, self::IssuesClosed, self::OpenBugs => GitHubPermission::Issues,
            self::DependabotAlerts => GitHubPermission::DependabotAlerts,
            self::CodeScanningAlerts => GitHubPermission::CodeScanningAlerts,
            self::SecretScanningAlerts => GitHubPermission::SecretScanningAlerts,
            default => null,
        };
    }

    /** Whether it shows until the connection says otherwise; stars say little about a private repository. */
    public function shownByDefault(bool $private): bool
    {
        return match ($this) {
            self::LeadTime, self::Authors, self::FailedRuns, self::LatestRelease, self::Forks, self::Watchers => false,
            self::Stars => ! $private,
            default => true,
        };
    }

    /**
     * Its keys in the page's summary or issues, left out of the page when it is hidden. The
     * commits and security alerts stay, as their tabs show them.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return match ($this) {
            self::LeadTime => ['lead_time_hours'],
            self::IssuesOpened => ['opened'],
            self::IssuesClosed => ['closed'],
            self::Commits, self::Authors, self::DependabotAlerts, self::CodeScanningAlerts, self::SecretScanningAlerts => [],
            default => [$this->value],
        };
    }
}
