<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * The last administrator who can sign in cannot delete their account: without
     * one, only the shell (lumberjack:make-admin) or a setup token gets back in.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if ($user?->isAdmin() && User::query()->whereActiveAdmin()->whereKeyNot($user->id)->doesntExist()) {
                    $validator->errors()->add('password', __('administration.last_admin'));
                }

                // A team others are in keeps an owner: it is handed over first.
                $shared = $user?->teams()->wherePivot('role', TeamRole::Owner->value)->withCount('members')->get()
                    ->first(fn (Team $team): bool => (int) $team->members_count > 1);

                if ($shared !== null) {
                    $validator->errors()->add('password', __('teams.transfer_before_deleting', ['team' => $shared->name]));
                }
            },
        ];
    }
}
