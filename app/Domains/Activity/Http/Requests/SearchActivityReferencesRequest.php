<?php

declare(strict_types=1);

namespace App\Domains\Activity\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What is typed into the actor or subject filter, for its suggestions; within one
 * activity when a group and name are given.
 */
class SearchActivityReferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('view', $project);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:actor,subject'],
            'q' => ['nullable', 'string', 'max:200'],
            'group' => ['nullable', 'string', 'max:64', 'required_with:name'],
            'name' => ['nullable', 'string', 'max:128', 'required_with:group'],
        ];
    }

    /** @return 'actor'|'subject' */
    public function kind(): string
    {
        return $this->string('kind')->toString() === 'subject' ? 'subject' : 'actor';
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public function event(): ?array
    {
        $group = $this->string('group')->toString();
        $name = $this->string('name')->toString();

        return $group !== '' && $name !== '' ? [$group, $name] : null;
    }
}
