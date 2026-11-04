<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Models\SavedScreenTemplate;
use App\Domains\Screens\Models\Screen;
use Illuminate\Foundation\Http\FormRequest;

/** A name and a description for a template: saving a screen as one, or renaming it. */
class SaveScreenTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $screen = $this->route('screen');
        $template = $this->route('template');

        return match (true) {
            $screen instanceof Screen => $this->user()?->can('view', $screen) === true
                && $this->user()->can('create', [SavedScreenTemplate::class, $screen->team]),
            $template instanceof SavedScreenTemplate => $this->user()?->can('update', $template) === true,
            default => false,
        };
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function description(): ?string
    {
        return $this->filled('description') ? $this->string('description')->trim()->toString() : null;
    }
}
