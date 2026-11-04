<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Enums\ScreenTemplate;
use App\Domains\Screens\Models\SavedScreenTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScreenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->user()?->currentTeam;

        return $team !== null && $this->user()->belongsToTeam($team);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'template' => ['sometimes', Rule::enum(ScreenTemplate::class)],
            // One the team saved, by uuid; another team's is not found.
            'saved_template' => ['sometimes', 'nullable', 'string', Rule::exists('screen_templates', 'uuid')->where('team_id', $this->user()?->currentTeam?->id)],
        ];
    }

    public function savedTemplate(): ?SavedScreenTemplate
    {
        return $this->filled('saved_template')
            ? SavedScreenTemplate::query()->where('uuid', $this->string('saved_template')->toString())->first()
            : null;
    }

    public function template(): ScreenTemplate
    {
        return $this->enum('template', ScreenTemplate::class) ?? ScreenTemplate::Blank;
    }
}
