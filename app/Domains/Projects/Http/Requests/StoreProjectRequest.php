<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->user()?->currentTeam;

        return $team !== null && $this->user()->canAdministerTeam($team);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['issue_prefix' => strtoupper(trim((string) $this->input('issue_prefix')))]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['issue_prefix.regex' => __('projects.issue_prefix_invalid')];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // Unique in the team, so a reference such as DEMO-42 points at one issue, for MCP too.
            'issue_prefix' => ['required', 'string', 'min:2', 'max:8', 'regex:/^[A-Z][A-Z0-9]*$/', Rule::unique('projects', 'issue_prefix')->where('team_id', $this->user()?->current_team_id)],
        ];
    }
}
