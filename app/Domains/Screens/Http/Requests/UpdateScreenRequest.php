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
        return [...self::rulesFor($this->screen()), 'revision' => ['nullable', 'integer', 'min:0']];
    }

    /** @return array<string, mixed> */
    public static function rulesFor(Screen $screen): array
    {
        $time = ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'theme' => ['sometimes', 'required', Rule::enum(ScreenTheme::class)],
            'aspect' => ['sometimes', 'required', Rule::enum(ScreenAspect::class)],
            'orientation' => ['sometimes', 'required', Rule::enum(ScreenOrientation::class)],
            'fit' => ['sometimes', 'required', Rule::enum(ScreenFit::class)],
            'show_header' => ['sometimes', 'required', 'boolean'],
            'show_clock' => ['sometimes', 'required', 'boolean'],
            'clock_format' => ['sometimes', 'required', Rule::in(['24', '12'])],
            'light_from' => ['sometimes', 'required', ...$time],
            'light_to' => ['sometimes', 'required', ...$time],
            // Empty: the viewer's own time zone.
            'timezone' => ['sometimes', 'nullable', 'timezone:all_with_bc', 'not_in:Factory'],
            'scope' => ['sometimes', 'required', 'array'],
            'scope.projects' => ['present_with:scope', 'array'],
            'scope.projects.*' => ['string', Rule::in($screen->team->projects()->pluck('uuid')->all())],
            'scope.environment' => ['nullable', 'string', 'max:64'],
            'scope.period' => ['required_with:scope', Rule::enum(Period::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return self::settingsFrom($this->validated());
    }

    /**
     * Validated input as the settings UpdateScreenAction takes.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function settingsFrom(array $input): array
    {
        $settings = array_intersect_key($input, array_flip(['name', 'light_from', 'light_to', 'timezone', 'clock_format']));

        foreach (['theme' => ScreenTheme::class, 'aspect' => ScreenAspect::class, 'orientation' => ScreenOrientation::class, 'fit' => ScreenFit::class] as $key => $enum) {
            if (array_key_exists($key, $input)) {
                $settings[$key] = $enum::from((string) $input[$key]);
            }
        }

        foreach (['show_header', 'show_clock'] as $key) {
            if (array_key_exists($key, $input)) {
                $settings[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (array_key_exists('scope', $input)) {
            $environment = $input['scope']['environment'] ?? null;
            $settings['scope'] = [
                'projects' => array_values(array_map('strval', (array) ($input['scope']['projects'] ?? []))),
                'environment' => $environment !== null && $environment !== '' ? (string) $environment : null,
                'period' => (string) $input['scope']['period'],
            ];
        }

        return $settings;
    }
}
