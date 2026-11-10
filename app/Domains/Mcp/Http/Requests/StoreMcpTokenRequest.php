<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Http\Requests;

use App\Domains\Mcp\Http\Requests\Concerns\ValidatesMcpAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMcpTokenRequest extends FormRequest
{
    use ValidatesMcpAccess;

    public const array EXPIRY_DAYS = [30, 90, 365];

    public function authorize(): bool
    {
        $team = $this->user()?->currentTeam;

        return $team !== null && $this->user()->canAdministerTeam($team);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', Rule::in(self::EXPIRY_DAYS)],
            ...$this->accessRules(),
        ];
    }
}
