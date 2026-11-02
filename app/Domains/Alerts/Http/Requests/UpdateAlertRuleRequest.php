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
        ];
    }
}
