<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A TV that lost its display cookie sends the copy of its token it kept in local storage.
 */
class RestoreDisplayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:100'],
        ];
    }
}
