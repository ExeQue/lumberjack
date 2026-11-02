<?php

declare(strict_types=1);

namespace App\Domains\Issues\Enums;

enum IssueEventType: string
{
    case Created = 'created';
    case Regressed = 'regressed';
    case Escalated = 'escalated';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case LabelsChanged = 'labels_changed';
    case RepoIssueCreated = 'repo_issue_created';
    case Returned = 'returned';
}
