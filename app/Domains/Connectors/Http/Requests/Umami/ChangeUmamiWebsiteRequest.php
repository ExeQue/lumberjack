<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Http\Requests\Umami;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Another website the saved credentials can read.
 */
class ChangeUmamiWebsiteRequest extends FormRequest
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
            'website_id' => ['required', 'string', 'max:64'],
        ];
    }
}
