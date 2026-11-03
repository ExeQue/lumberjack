<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Domains\Administration\Data\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;

class NotificationPreferencesRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [];

        foreach (NotificationPreferences::KINDS as $kind) {
            $rules[$kind.'.mail'] = ['required', 'boolean'];
            $rules[$kind.'.toast'] = ['required', 'boolean'];
        }

        return $rules;
    }

    public function preferences(): NotificationPreferences
    {
        return NotificationPreferences::fromArray(array_map(fn (mixed $v): array => array_map(fn (mixed $b): bool => filter_var($b, FILTER_VALIDATE_BOOL), (array) $v), $this->only(NotificationPreferences::KINDS)));
    }
}
