<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Requests;

use App\Domains\Teams\Enums\TeamRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'team' => ['required', 'string', 'exists:teams,uuid'],
            'role' => ['required', Rule::enum(TeamRole::class)->except([TeamRole::Owner])],
        ];
    }
}
