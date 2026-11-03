<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // A new email address takes the password too, so a stolen session cannot move the
        // account (and its password resets) to someone else's inbox.
        return [
            ...$this->profileRules($this->user()->id),
            'current_password' => [Rule::requiredIf($this->input('email') !== $this->user()->email), 'nullable', 'string', 'current_password'],
        ];
    }
}
