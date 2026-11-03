<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDisplayRequest extends FormRequest
{
    public function display(): Display
    {
        /** @var Display */
        return $this->route('display');
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->display()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:60'],
            // One of the display's team's screens, or none.
            'screen' => ['sometimes', 'nullable', 'uuid', Rule::exists('screens', 'uuid')->where('team_id', $this->display()->team_id)],
            'theme' => ['sometimes', Rule::enum(DisplayTheme::class)],
        ];
    }

    /**
     * @return array{name?: string, screen_id?: int|null, theme?: DisplayTheme}
     */
    public function settings(): array
    {
        $settings = [];

        if ($this->has('name')) {
            $settings['name'] = $this->string('name')->toString();
        }

        if ($this->has('screen')) {
            $settings['screen_id'] = $this->filled('screen') ? Screen::query()->where('uuid', $this->string('screen')->toString())->value('id') : null;
        }

        if ($this->has('theme')) {
            $settings['theme'] = $this->enum('theme', DisplayTheme::class);
        }

        return $settings;
    }
}
