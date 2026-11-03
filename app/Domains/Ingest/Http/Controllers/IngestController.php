<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Http\Controllers;

use App\Domains\Ingest\Http\Requests\BrowserIngestRequest;
use App\Domains\Ingest\Http\Requests\HeartbeatRequest;
use App\Domains\Ingest\Http\Requests\IngestBatchRequest;
use App\Domains\Ingest\Jobs\ProcessIngestBatch;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Projects\Actions\RecordAgentHeartbeatAction;
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

    public function heartbeat(HeartbeatRequest $request, RecordAgentHeartbeatAction $action, BroadcastProjectChangeAction $broadcast): JsonResponse
    {
        $project = $request->project();
        $action->execute($project, $request->string('host')->toString(), $request->client(), $request->input('name'));
        $broadcast->execute($project->id, ChangeKind::Agents);

        return response()->json(['ok' => true]);
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
