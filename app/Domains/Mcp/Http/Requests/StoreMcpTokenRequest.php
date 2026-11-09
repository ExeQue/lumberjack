<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Http\Requests;

use App\Domains\Mcp\Enums\McpScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMcpTokenRequest extends FormRequest
{
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
            'scope' => ['required', Rule::enum(McpScope::class)],
            'can_design_screens' => ['sometimes', 'boolean'],
            'projects' => ['required', 'array', 'min:1'],
            'projects.*' => ['uuid'],
            'expires_in_days' => ['nullable', 'integer', Rule::in(self::EXPIRY_DAYS)],
        ];
    }
}
