<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Http\Requests;

use App\Domains\Ingest\Http\Requests\Concerns\DecodesIngestBody;
use App\Domains\Projects\Models\Project;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class IngestBatchRequest extends FormRequest
{
    use DecodesIngestBody;

    public const int MAX_EVENTS = 5000;

    public const array TYPES = ['request', 'query', 'exception', 'job', 'log', 'schedule', 'command', 'span', 'health'];

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
            // One pass over the events: a wildcard rule expands into thousands of rules for a full batch.
            'events' => ['required', 'array', 'max:'.self::MAX_EVENTS, function (string $attribute, mixed $events, Closure $fail): void {
                foreach (is_array($events) ? $events : [] as $i => $event) {
                    if (! is_array($event) || ! in_array($event['type'] ?? null, self::TYPES, true)) {
                        $fail(__('validation.in', ['attribute' => "{$attribute}.{$i}.type"]));

                        return;
                    }
                }
            }],
        ];
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->attributes->get('project');
    }
}
