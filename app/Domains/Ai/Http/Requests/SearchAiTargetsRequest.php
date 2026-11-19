<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What is typed into a key, key owner or actor picker of the budget or price form.
 */
class SearchAiTargetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('view', $project) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:keys,owners,actors'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return 'keys'|'owners'|'actors' */
    public function kind(): string
    {
        return match ($this->string('kind')->toString()) {
            'owners' => 'owners',
            'actors' => 'actors',
            default => 'keys',
        };
    }

    public function term(): string
    {
        return trim($this->string('q')->toString());
    }
}
