<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScreenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->user()?->currentTeam;

        return $team !== null && $this->user()->belongsToTeam($team);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
