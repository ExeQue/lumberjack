<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Requests\Concerns;

use App\Domains\Projects\Models\Project;
use Illuminate\Validation\Rule;

/**
 * A bulk action on the issues list: the issues picked there, all in the route's project.
 * Every member may change issues, as on one issue.
 */
trait TargetsSelectedIssues
{
    public function authorize(): bool
    {
        return $this->project() !== null && $this->user()?->can('view', $this->project());
    }

    /** @return list<string> */
    public function issueUuids(): array
    {
        return array_values(array_filter($this->array('issues'), is_string(...)));
    }

    /** @return array<string, mixed> */
    protected function issueRules(): array
    {
        return self::issueRulesFor($this->project());
    }

    /**
     * The picked issues: up to 100 issue uuids, all in the project. MCP's bulk_update_issues reuses them.
     *
     * @return array<string, mixed>
     */
    public static function issueRulesFor(?Project $project): array
    {
        return [
            'issues' => ['required', 'array', 'max:100'],
            'issues.*' => ['uuid', Rule::exists('issues', 'uuid')->where('project_id', $project?->id)],
        ];
    }

    private function project(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }
}
