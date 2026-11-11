<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Enums;

use App\Domains\DataSources\Enums\DataSourceShape;

/**
 * The data sources a GitHub repository offers screens.
 */
enum GitHubSource: string
{
    case OpenPullRequests = 'open_pull_requests';
    case WaitingForReview = 'waiting_for_review';
    case Merged = 'merged';
    case FailedRuns = 'failed_runs';
    case OpenIssues = 'open_issues';
    case CiStatus = 'ci_status';
    case LatestRelease = 'latest_release';
    case Commits = 'commits';
    case CommitsSeries = 'commits.series';
    case Authors = 'authors';
    case LatestCommits = 'latest_commits';
    case TopContributors = 'top_contributors';
    case PullRequestList = 'pull_request_list';
    case LeadTime = 'lead_time';
    case Stars = 'stars';
    case Forks = 'forks';
    case Watchers = 'watchers';
    case IssuesOpened = 'issues_opened';
    case IssuesClosed = 'issues_closed';
    case OpenBugs = 'open_bugs';
    case DependabotAlerts = 'dependabot_alerts';
    case DependabotBySeverity = 'dependabot_by_severity';
    case CodeScanningAlerts = 'code_scanning_alerts';
    case SecretScanningAlerts = 'secret_scanning_alerts';

    public function label(): string
    {
        return __('connectors.github.sources.'.str_replace('.', '_', $this->value));
    }

    /** The optional permission it needs, if any; the catalog leaves it out without. */
    public function permission(): ?GitHubPermission
    {
        return match ($this) {
            self::IssuesOpened, self::IssuesClosed, self::OpenBugs => GitHubPermission::Issues,
            self::DependabotAlerts, self::DependabotBySeverity => GitHubPermission::DependabotAlerts,
            self::CodeScanningAlerts => GitHubPermission::CodeScanningAlerts,
            self::SecretScanningAlerts => GitHubPermission::SecretScanningAlerts,
            default => null,
        };
    }

    /** Whether it is read from the default branch's commits in the range. */
    public function readsCommits(): bool
    {
        return in_array($this, [self::Commits, self::CommitsSeries, self::Authors, self::LatestCommits, self::TopContributors], true);
    }

    public function shape(): DataSourceShape
    {
        return match ($this) {
            self::CiStatus, self::LatestRelease, self::LatestCommits, self::TopContributors, self::PullRequestList, self::DependabotBySeverity => DataSourceShape::List,
            self::CommitsSeries => DataSourceShape::Series,
            default => DataSourceShape::Number,
        };
    }
}
