<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A screen's data for a viewer without a session (a paired TV, a share link); the
 * controller checks the display token or share key.
 */
class ViewerDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'widget' => ['nullable', 'string', 'max:40'],
            // A display showing screens in turn asks for each by uuid.
            'screen' => ['nullable', 'uuid'],
        ];
    }

    public function widget(): ?string
    {
        return $this->filled('widget') ? $this->string('widget')->toString() : null;
    }
}
