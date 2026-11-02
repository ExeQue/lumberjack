<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Requests;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SetupRequest extends FormRequest
{
    use PasswordValidationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
            'token' => ['nullable', 'string'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $token = (string) config('app.setup_token');

                if ($token !== '' && ! hash_equals($token, (string) $this->input('token'))) {
                    $validator->errors()->add('token', __('administration.setup.invalid_token'));
                }
            },
        ];
    }
}
