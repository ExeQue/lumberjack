<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Http\Requests;

use App\Domains\Ingest\Http\Requests\Concerns\DecodesIngestBody;
use App\Domains\Performance\Actions\RecordRequestCountsAction;
use App\Domains\Projects\Models\Project;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class IngestBatchRequest extends FormRequest
{
    use DecodesIngestBody;

    public const int MAX_EVENTS = 5000;

    public const array TYPES = ['request', 'query', 'exception', 'job', 'log', 'schedule', 'command', 'span', 'health', 'request_counts', 'alert', 'maintenance', 'activity'];

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
                    if (! is_array($event) || ! is_string($event['type'] ?? null)) {
                        $fail(__('validation.in', ['attribute' => "{$attribute}.{$i}.type"]));

                        return;
                    }

                    // A newer agent's event is skipped, so it cannot cost the rest of its batch.
                    if (! in_array($event['type'], self::TYPES, true)) {
                        continue;
                    }

                    $counts = $event['type'] === 'request_counts' ? ($event['counts'] ?? null) : [];

                    if (! is_array($counts) || ! array_is_list($counts)) {
                        $fail(__('validation.list', ['attribute' => "{$attribute}.{$i}.counts"]));

                        return;
                    }

                    if (count($counts) > RecordRequestCountsAction::MAX_ROWS) {
                        $fail(__('validation.max.array', ['attribute' => "{$attribute}.{$i}.counts", 'max' => RecordRequestCountsAction::MAX_ROWS]));

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
