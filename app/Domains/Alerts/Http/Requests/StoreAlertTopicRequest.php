<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAlertTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->user()?->currentTeam;

        return $team !== null && $this->user()->can('administer', $team);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fieldRules();
    }

    /**
     * The rules without the request, for MCP tools that take the same input.
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
        ];
    }
}
