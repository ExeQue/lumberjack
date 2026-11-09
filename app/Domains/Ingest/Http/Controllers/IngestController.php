<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Http\Controllers;

use App\Domains\Health\Queries\HealthChecksQuery;
use App\Domains\Ingest\Http\Requests\BrowserIngestRequest;
use App\Domains\Ingest\Http\Requests\HeartbeatRequest;
use App\Domains\Ingest\Http\Requests\IngestBatchRequest;
use App\Domains\Ingest\Jobs\ProcessIngestBatch;
use App\Domains\Jobs\Actions\RecordQueueSamplesAction;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Projects\Actions\RecordAgentHeartbeatAction;
use App\Domains\Projects\Actions\RecordHostSampleAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IngestController
{
    public function store(IngestBatchRequest $request): JsonResponse
    {
        ProcessIngestBatch::dispatch($request->project()->id, [...$request->validated(), 'events' => $request->input('events')]);

        return response()->json(['accepted' => count((array) $request->input('events'))], 202);
    }

    public function browser(BrowserIngestRequest $request): JsonResponse
    {
        $project = $request->project();
        $events = count((array) $request->input('events'));

        // A bot gets the same answer, so it has no reason to retry; nothing is stored.
        if (! $request->fromBot()) {
            ProcessIngestBatch::dispatch((int) $project?->id, [...$request->validated(), 'events' => $request->input('events')], fromBrowser: true);
        }

        return response()
            ->json(['accepted' => $request->fromBot() ? 0 : $events], 202)
            ->withHeaders($this->corsHeaders((string) $request->header('Origin')));
    }

    public function browserPreflight(Request $request): Response
    {
        return response()->noContent()->withHeaders($this->corsHeaders((string) $request->header('Origin')));
    }

    public function heartbeat(HeartbeatRequest $request, RecordAgentHeartbeatAction $action, RecordQueueSamplesAction $queues, RecordHostSampleAction $hostSample, BroadcastProjectChangeAction $broadcast, HealthChecksQuery $health): JsonResponse
    {
        $project = $request->project();
        $host = $request->string('host')->toString();
        $agent = $action->execute($project, $host, $request->client(), $request->input('name'));
        $hostSample->execute($agent, $request->metrics());
        $queues->execute($project, $host, $request->queues());
        $broadcast->execute($project->id, ChangeKind::Agents);

        // The app reads its health check states back from this answer, through the agent.
        return response()->json(['ok' => true, 'features' => IngestBatchRequest::TYPES, 'health' => $health->states($project, $request->environment())]);
    }

    /**
     * @return array<string, string>
     */
    private function corsHeaders(string $origin): array
    {
        return [
            'Access-Control-Allow-Origin' => $origin !== '' ? $origin : '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ];
    }
}
