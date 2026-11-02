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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash:ascii', Rule::unique('projects', 'slug')->where('team_id', $project->team_id)->ignore($project->id)],
            'allowed_origins' => ['present', 'array', 'max:50'],
            'allowed_origins.*' => ['string', 'url:http,https', 'max:255'],
            'scrub_keys' => ['sometimes', 'array', 'max:50'],
            'scrub_keys.*' => ['string', 'max:64', 'regex:/^[A-Za-z0-9_.*-]+$/'],
        ];
    }
}
