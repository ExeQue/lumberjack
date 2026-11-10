<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A note for a day, or for a moment of it when a time is given, in the connection's zone.
 */
class CreateUmamiAnnotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project) === true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'note' => ['required', 'string', 'max:200'],
        ];
    }
}
