<?php

declare(strict_types=1);

namespace App\Domains\Retention\Http\Requests;

use App\Domains\Retention\Enums\RetentionCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorization is checked by the controller against the project or team in the route.
 */
class UpdateRetentionRequest extends FormRequest
{
    public const int MAX_DAYS = 3650;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];

        foreach (RetentionCategory::cases() as $category) {
            $rules['days.'.$category->value] = ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS];
        }

        return [
            'days' => ['array'],
            ...$rules,
            'job_overrides' => ['sometimes', 'array', 'max:100'],
            'job_overrides.*.job_class' => ['required', 'string', 'max:255'],
            'job_overrides.*.days' => ['required', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
        ];
    }
}
