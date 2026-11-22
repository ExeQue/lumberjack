<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectAlertTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return StoreAlertTopicRequest::fieldRules();
    }
}
