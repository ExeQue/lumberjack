<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Enums\ScreenAspect;
use App\Domains\Screens\Enums\ScreenFit;
use App\Domains\Screens\Enums\ScreenOrientation;
use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Models\Screen;
use App\Domains\Shared\Enums\Period;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScreenRequest extends FormRequest
{
    public function screen(): Screen
    {
        /** @var Screen */
        return $this->route('screen');
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->screen()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $time = ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'theme' => ['sometimes', 'required', Rule::enum(ScreenTheme::class)],
            'aspect' => ['sometimes', 'required', Rule::enum(ScreenAspect::class)],
            'orientation' => ['sometimes', 'required', Rule::enum(ScreenOrientation::class)],
            'fit' => ['sometimes', 'required', Rule::enum(ScreenFit::class)],
            'light_from' => ['sometimes', 'required', ...$time],
            'light_to' => ['sometimes', 'required', ...$time],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'scope' => ['sometimes', 'required', 'array'],
            'scope.projects' => ['present_with:scope', 'array'],
            'scope.projects.*' => ['string', Rule::in($this->screen()->team->projects()->pluck('uuid')->all())],
            'scope.environment' => ['nullable', 'string', 'max:64'],
            'scope.period' => ['required_with:scope', Rule::enum(Period::class)],
        ];
    }

    /**
     * @return array{name?: string, theme?: ScreenTheme, aspect?: ScreenAspect, orientation?: ScreenOrientation, fit?: ScreenFit, light_from?: string, light_to?: string, timezone?: string, scope?: array{projects: list<string>, environment: string|null, period: string}}
     */
    public function settings(): array
    {
        $settings = $this->safe()->only(['name', 'light_from', 'light_to', 'timezone']);

        if ($this->has('theme')) {
            $settings['theme'] = ScreenTheme::from($this->string('theme')->toString());
        }

        if ($this->has('aspect')) {
            $settings['aspect'] = ScreenAspect::from($this->string('aspect')->toString());
        }

        if ($this->has('orientation')) {
            $settings['orientation'] = ScreenOrientation::from($this->string('orientation')->toString());
        }

        if ($this->has('fit')) {
            $settings['fit'] = ScreenFit::from($this->string('fit')->toString());
        }

        if ($this->has('scope')) {
            $settings['scope'] = [
                'projects' => array_values(array_map('strval', (array) $this->input('scope.projects', []))),
                'environment' => $this->filled('scope.environment') ? $this->string('scope.environment')->toString() : null,
                'period' => $this->string('scope.period')->toString(),
            ];
        }

        return $settings;
    }
}
