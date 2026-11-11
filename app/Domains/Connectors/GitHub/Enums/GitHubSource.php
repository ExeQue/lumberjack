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

    public function label(): string
    {
        return __('connectors.github.sources.'.$this->value);
    }

    public function shape(): DataSourceShape
    {
        return match ($this) {
            self::CiStatus, self::LatestRelease => DataSourceShape::List,
            default => DataSourceShape::Number,
        };
    }
}
