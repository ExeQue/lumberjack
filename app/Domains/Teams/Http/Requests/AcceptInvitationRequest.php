<?php

declare(strict_types=1);

namespace App\Domains\Teams\Http\Requests;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A signed-in user accepts as themselves; a newcomer picks a name and password.
 */
class AcceptInvitationRequest extends FormRequest
{
    use PasswordValidationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->user() !== null) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ];
    }
}
