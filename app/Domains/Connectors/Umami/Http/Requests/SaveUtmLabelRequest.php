<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Http\Requests;

use App\Domains\Connectors\Umami\Enums\UtmDimension;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A name for a UTM value, or none to forget it.
 */
class SaveUtmLabelRequest extends FormRequest
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
            'dimension' => ['required', Rule::enum(UtmDimension::class)],
            'value' => ['required', 'string', 'max:500'],
            'label' => ['nullable', 'string', 'max:100'],
        ];
    }
}
