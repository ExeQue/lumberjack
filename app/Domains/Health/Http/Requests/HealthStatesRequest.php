<?php

declare(strict_types=1);

namespace App\Domains\Health\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class HealthStatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('project') instanceof Project;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'slug' => ['nullable', 'string', 'max:255'],
            'environment' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function slug(): ?string
    {
        return $this->filledString('slug');
    }

    public function environment(): ?string
    {
        return $this->filledString('environment');
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->attributes->get('project');
    }

    private function filledString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
