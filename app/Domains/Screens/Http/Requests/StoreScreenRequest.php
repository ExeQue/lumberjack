<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Requests;

use App\Domains\Screens\Enums\ScreenTemplate;
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
        ];
    }

    public function template(): ScreenTemplate
    {
        return $this->enum('template', ScreenTemplate::class) ?? ScreenTemplate::Blank;
    }
}
