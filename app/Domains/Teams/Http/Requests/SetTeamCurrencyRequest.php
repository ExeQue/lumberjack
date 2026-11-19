<?php

declare(strict_types=1);

namespace App\Domains\Teams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The team's currency, as an ISO 4217 code such as USD or EUR.
 */
class SetTeamCurrencyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
        ];
    }
}
