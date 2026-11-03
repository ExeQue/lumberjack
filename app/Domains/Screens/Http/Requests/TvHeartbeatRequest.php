<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A paired TV's heartbeat, with the size of its window in CSS pixels and its pixel
 * ratio, so the designer can preview a screen at that size. All optional: an older TV
 * page sends none.
 */
class TvHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'w' => ['nullable', 'integer', 'min:100', 'max:20000'],
            'h' => ['nullable', 'integer', 'min:100', 'max:20000'],
            'dpr' => ['nullable', 'numeric', 'min:0.25', 'max:8'],
        ];
    }

    /** @return array{w: int, h: int, dpr: float}|null */
    public function viewport(): ?array
    {
        if (! $this->filled('w') || ! $this->filled('h')) {
            return null;
        }

        return ['w' => $this->integer('w'), 'h' => $this->integer('h'), 'dpr' => $this->filled('dpr') ? (float) $this->input('dpr') : 1.0];
    }
}
