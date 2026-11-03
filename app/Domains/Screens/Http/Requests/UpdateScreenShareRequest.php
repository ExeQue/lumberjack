<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Models\Screen;
use Illuminate\Foundation\Http\FormRequest;

class UpdateScreenShareRequest extends FormRequest
{
    public function screen(): Screen
    {
        /** @var Screen */
        return $this->route('screen');
    }

    public function authorize(): bool
    {
        return $this->user()?->can('share', $this->screen()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'include_titles' => ['sometimes', 'boolean'],
        ];
    }
}
