<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Http\Requests;

use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('project') instanceof Project;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->attributes->get('project');
    }
}
