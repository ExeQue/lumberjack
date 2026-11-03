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

        return $rule instanceof AlertRule && $this->user()?->can('update', $rule->project);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'channels' => ['present', 'array'],
            'channels.*' => ['integer'],
            'environments' => ['nullable', 'array'],
            'environments.*' => ['string', 'max:64'],
            // Queue backlog thresholds; at least one of size and age.
            'settings' => ['nullable', 'array:size,age_minutes,sustain_minutes'],
            'settings.size' => ['nullable', 'integer', 'min:1', 'required_without:settings.age_minutes'],
            'settings.age_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'settings.sustain_minutes' => ['required_with:settings', 'integer', 'min:1', 'max:1440'],
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
