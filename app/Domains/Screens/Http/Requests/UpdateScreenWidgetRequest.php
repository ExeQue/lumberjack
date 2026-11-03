<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\WidgetSchema;
use Illuminate\Foundation\Http\FormRequest;

class UpdateScreenWidgetRequest extends FormRequest
{
    public function screen(): Screen
    {
        /** @var Screen */
        return $this->route('screen');
    }

    /**
     * The widget id comes from the URL; the body may not move it to another.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['widget' => [...(array) $this->input('widget', []), 'id' => (string) $this->route('widget')]]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->screen()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $screen = $this->screen();

        return WidgetSchema::rules('widget', $this->input('widget'), $screen->cols, $screen->rows, array_values($screen->team->projects()->pluck('uuid')->map(fn (mixed $uuid): string => (string) $uuid)->all()));
    }
}
