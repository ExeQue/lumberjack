<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Http\Requests;

use App\Domains\Ingest\Http\Requests\Concerns\DecodesIngestBody;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class IngestBatchRequest extends FormRequest
{
    use DecodesIngestBody;

    public const int MAX_EVENTS = 5000;

    public function authorize(): bool
    {
        return $this->attributes->get('project') instanceof Project;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'v' => ['required', 'integer', 'in:1'],
            'environment' => ['nullable', 'string', 'max:64'],
            'release' => ['nullable', 'string', 'max:64'],
            'host' => ['nullable', 'string', 'max:255'],
            'events' => ['required', 'array', 'max:'.self::MAX_EVENTS],
            'events.*.type' => ['required', 'string', 'in:request,query,exception,job,log,schedule,command'],
        ];
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->attributes->get('project');
    }
}
