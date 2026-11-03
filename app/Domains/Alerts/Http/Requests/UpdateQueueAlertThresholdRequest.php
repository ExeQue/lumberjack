<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One queue's own backlog thresholds; whoever may change the project may set them.
 */
class UpdateQueueAlertThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'connection' => ['required', 'string', 'max:255'],
            'queue' => ['required', 'string', 'max:255'],
            'size' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'oldest_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'sustained_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'muted' => ['required', 'boolean'],
        ];
    }

    public function integerOrNull(string $key): ?int
    {
        return $this->filled($key) ? $this->integer($key) : null;
    }
}
