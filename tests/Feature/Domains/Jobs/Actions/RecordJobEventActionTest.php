<?php

declare(strict_types=1);

use App\Domains\Ingest\Data\Envelope;
use App\Domains\Jobs\Actions\RecordJobEventAction;
use App\Domains\Projects\Models\Project;

covers(RecordJobEventAction::class);

describe('job parameters', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create(['scrub_keys' => ['iban']]);
        $this->envelope = new Envelope('production', 'abc', 'web-1');
        $this->event = fn (string $status, array $extra = []): array => ['type' => 'job', 'status' => $status, 'uuid' => '9f1c6b3e-0000-4000-8000-000000000001', 'name' => 'App\\Jobs\\SendInvoice', 'queue' => 'default', 'attempt' => 1, ...$extra];
    });

    it('keeps the parameters from the queued event, scrubbed, through the later events', function () {
        $action = app(RecordJobEventAction::class);

        $action->execute($this->project, ($this->event)('queued', ['parameters' => ['order' => 'App\\Models\\Order#42', 'api_token' => 'secret', 'iban' => 'DK50']]), $this->envelope);
        $run = $action->execute($this->project, ($this->event)('processed', ['duration_ms' => 12]), $this->envelope);

        expect($run?->parameters)->toEqual(['order' => 'App\\Models\\Order#42', 'api_token' => '[filtered]', 'iban' => '[filtered]']);
    });

    it('stores a marker instead of parameters that are too big', function () {
        $run = app(RecordJobEventAction::class)->execute($this->project, ($this->event)('queued', ['parameters' => ['blob' => str_repeat('x', RecordJobEventAction::MAX_PARAMETER_BYTES)]]), $this->envelope);

        expect($run?->parameters)->toBe(['_truncated' => true]);
    });
});
