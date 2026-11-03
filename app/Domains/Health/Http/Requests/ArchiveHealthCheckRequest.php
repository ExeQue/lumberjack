<?php

declare(strict_types=1);

namespace App\Domains\Health\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveHealthCheckRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['archived' => ['required', 'boolean']];
    }
}
