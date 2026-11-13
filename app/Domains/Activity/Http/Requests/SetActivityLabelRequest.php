<?php

declare(strict_types=1);

namespace App\Domains\Activity\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Names an activity group, or one activity when a name is given. Team admins only.
 */
class SetActivityLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && (bool) $this->user()?->can('update', $project);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'group' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:128'],
            'label' => ['nullable', 'string', 'max:100'],
        ];
    }
}
