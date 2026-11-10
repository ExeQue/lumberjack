<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Http\Requests;

use App\Domains\Mcp\Http\Requests\Concerns\ValidatesMcpAccess;
use App\Domains\Mcp\Models\McpToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changes what a token may do and where; its secret stays the same.
 */
class UpdateMcpTokenRequest extends FormRequest
{
    use ValidatesMcpAccess;

    public function authorize(): bool
    {
        $token = $this->route('token');

        return $token instanceof McpToken && $this->user() !== null && $this->user()->canAdministerTeam($token->team);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            ...$this->accessRules(),
        ];
    }
}
