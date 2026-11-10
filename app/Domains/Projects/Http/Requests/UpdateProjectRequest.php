<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('issue_prefix')) {
            $this->merge(['issue_prefix' => strtoupper(trim((string) $this->input('issue_prefix')))]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['issue_prefix.regex' => __('projects.issue_prefix_invalid')];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return self::rulesFor($project);
    }

    /**
     * Also used by MCP's update_project, which sends the issue prefix upper-cased already.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(Project $project): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash:ascii', Rule::unique('projects', 'slug')->where('team_id', $project->team_id)->ignore($project->id)],
            // Unique in the team, so a reference such as DEMO-42 points at one issue, for MCP too.
            'issue_prefix' => ['sometimes', 'required', 'string', 'min:2', 'max:8', 'regex:/^[A-Z][A-Z0-9]*$/', Rule::unique('projects', 'issue_prefix')->where('team_id', $project->team_id)->ignore($project->id)],
            'allowed_origins' => ['present', 'array', 'max:50'],
            'allowed_origins.*' => ['string', 'url:http,https', 'max:255'],
            'scrub_keys' => ['sometimes', 'array', 'max:50'],
            'scrub_keys.*' => ['string', 'max:64', 'regex:/^[A-Za-z0-9_.*-]+$/'],
        ];
    }
}
