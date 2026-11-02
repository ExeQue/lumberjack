<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Http\Requests;

use App\Domains\Projects\Models\Project;
use App\Domains\Repositories\Enums\RepositoryProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRepositoryRequest extends FormRequest
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
            'provider' => ['required', Rule::enum(RepositoryProvider::class)],
            'repository' => ['required', 'string', 'max:255', 'regex:#^[\w.-]+/[\w.-]+$#'],
            'token' => [$project->repository === null ? 'required' : 'nullable', 'string', 'max:255'],
            'token_expires_at' => ['nullable', 'date'],
            'labels' => ['array', 'max:10'],
            'labels.*' => ['string', 'max:50'],
            'allow_public' => ['boolean'],
        ];
    }
}
