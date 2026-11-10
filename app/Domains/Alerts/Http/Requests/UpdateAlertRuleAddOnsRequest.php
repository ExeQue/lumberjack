<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Requests;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAlertRuleAddOnsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $rule = $this->route('rule');

        // Add-ons go on the team rule the project inherits, never on another team's.
        abort_unless($project instanceof Project && $rule instanceof AlertRule && $rule->team_id === $project->team_id, 404);

        return $this->user()?->can('update', $project) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fieldRules();
    }

    /**
     * The rules without the request, for MCP tools that take the same input.
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(): array
    {
        return [
            'channels' => ['present', 'array'],
            'channels.*' => ['integer'],
        ];
    }
}
