<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Models\Screen;
use Illuminate\Foundation\Http\FormRequest;

class ScreenDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        $screen = $this->route('screen');

        return $screen instanceof Screen && ($this->user()?->can('view', $screen) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'widget' => ['nullable', 'string', 'max:40'],
        ];
    }
}
