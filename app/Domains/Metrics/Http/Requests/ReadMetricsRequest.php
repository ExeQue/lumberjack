<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Requests;

use App\Domains\Shared\Enums\Period;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The summary and the tables: a period ending now, as on the project pages.
 */
class ReadMetricsRequest extends FormRequest
{
    public const int MAX_LIMIT = 500;

    public function authorize(): bool
    {
        // The token or public key was checked by AuthenticateMetricsCredential.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', Rule::enum(Period::class)],
            'environment' => ['sometimes', 'nullable', 'string', 'max:64'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function period(): Period
    {
        return Period::tryFrom($this->string('period')->toString()) ?? Period::Day;
    }

    public function environment(): ?string
    {
        $environment = $this->string('environment')->toString();

        return $environment !== '' ? $environment : null;
    }

    public function limit(int $default = 100): int
    {
        return $this->integer('limit', $default);
    }
}
