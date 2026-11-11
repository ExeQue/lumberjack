<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Requests;

use App\Domains\Alerts\Enums\AlertRuleType;
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
        $rule = $this->route('rule');

        return self::fieldRules($rule instanceof AlertRule ? $rule->type : AlertRuleType::QueueBacklog);
    }

    /**
     * The rules without the request, for MCP tools that take the same input. Settings are
     * the type's own: a queue backlog's thresholds (at least one of size and age), or a
     * weekly summary's day and time; any other type has none.
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(AlertRuleType $type): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'channels' => ['present', 'array'],
            'channels.*' => ['integer'],
            'environments' => ['nullable', 'array'],
            'environments.*' => ['string', 'max:64'],
            ...match ($type) {
                AlertRuleType::QueueBacklog => [
                    'settings' => ['nullable', 'array:size,age_minutes,sustain_minutes'],
                    'settings.size' => ['exclude_without:settings', 'nullable', 'integer', 'min:1', 'required_without:settings.age_minutes'],
                    'settings.age_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
                    'settings.sustain_minutes' => ['required_with:settings', 'integer', 'min:1', 'max:1440'],
                ],
                AlertRuleType::WeeklySummary => [
                    'settings' => ['nullable', 'array:day,time'],
                    'settings.day' => ['required_with:settings', 'integer', 'between:1,7'],
                    'settings.time' => ['required_with:settings', 'date_format:H:i'],
                ],
                default => ['settings' => ['prohibited']],
            },
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
