<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Requests;

use App\Domains\Alerts\Models\AlertRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rule = $this->route('rule');

        // A team rule is the team's to change; a project's own, the project's.
        return $rule instanceof AlertRule && ($rule->project === null
            ? $this->user()?->can('administer', $rule->team)
            : $this->user()?->can('update', $rule->project)) === true;
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
            'enabled' => ['required', 'boolean'],
            'channels' => ['present', 'array'],
            'channels.*' => ['integer'],
            'environments' => ['nullable', 'array'],
            'environments.*' => ['string', 'max:64'],
            // Queue backlog thresholds, at least one of size and age; or a weekly summary's day and time.
            'settings' => ['nullable', 'array:size,age_minutes,sustain_minutes,day,time'],
            'settings.size' => ['exclude_without:settings', 'exclude_with:settings.day', 'nullable', 'integer', 'min:1', 'required_without:settings.age_minutes'],
            'settings.age_minutes' => ['exclude_with:settings.day', 'nullable', 'integer', 'min:1', 'max:10080'],
            'settings.sustain_minutes' => ['exclude_with:settings.day', 'required_with:settings', 'integer', 'min:1', 'max:1440'],
            'settings.day' => ['required_with:settings.time', 'integer', 'between:1,7'],
            'settings.time' => ['required_with:settings.day', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function settings(): ?array
    {
        $settings = $this->validated('settings');

        return is_array($settings) ? $settings : null;
    }
}
