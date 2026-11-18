<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Requests;

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MoveProjectRequest extends FormRequest
{
    public function project(): Project
    {
        /** @var Project */
        return $this->route('project');
    }

    /** The team it moves to; null when there is none by that uuid, which validation reports. */
    public function target(): ?Team
    {
        $uuid = (string) $this->input('team');

        return once(fn (): ?Team => Str::isUuid($uuid) ? Team::query()->where('uuid', $uuid)->first() : null);
    }

    public function authorize(): bool
    {
        $target = $this->target();

        return $target === null
            ? $this->user()?->can('update', $this->project()) ?? false
            : $this->user()?->can('move', [$this->project(), $target]) ?? false;
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
        $teamId = $this->target()?->id;

        return [
            'team' => ['bail', 'required', 'uuid', Rule::exists('teams', 'uuid')],
            // Free in the team it moves to, as on creating one there.
            'slug' => ['required', 'string', 'max:100', 'alpha_dash:ascii', Rule::unique('projects', 'slug')->where('team_id', $teamId)],
            'issue_prefix' => ['required', 'string', 'min:2', 'max:8', 'regex:/^[A-Z][A-Z0-9]*$/', Rule::unique('projects', 'issue_prefix')->where('team_id', $teamId)],
        ];
    }
}
