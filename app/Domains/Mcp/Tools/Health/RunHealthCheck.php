<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Health;

use App\Domains\Health\Actions\RunHealthCheckAction;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesHealthChecks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Health, McpLevel::Write)]
class RunHealthCheck extends Tool
{
    use UsesHealthChecks;

    /** Runs per check a minute, so an agent cannot turn a check into a probe loop. */
    public const int RUNS_PER_MINUTE = 2;

    protected string $name = 'run_health_check';

    protected string $description = 'Runs a check Lumberjack runs now, waits for it (up to about a minute) and records the result like a scheduled run. A heartbeat only records a result when its ping is overdue; a data_source check only when it has no result yet (new, or its settings changed) or its source is not fetched in time, and always when its source or period is missing (it never fetches the source itself: it reads the latest fetch, and fetches follow the source\'s own schedule). Checks the app reports cannot be run from here.';

    public function __construct(
        private readonly RunHealthCheckAction $run,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'check' => $this->checkArgument($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $check = $this->healthCheck($request, 'update');

        if ($check instanceof Response) {
            return $check;
        }

        if ($check->isReported()) {
            return Response::error('The app runs this check itself and reports it; it cannot be run from Lumberjack.');
        }

        $limiter = 'mcp-run-health-check:'.$check->id;

        if (RateLimiter::tooManyAttempts($limiter, self::RUNS_PER_MINUTE)) {
            return Response::error('This check ran just now; wait a minute or read its result with get_health_check.');
        }

        RateLimiter::hit($limiter);
        $result = $this->run->execute($check);

        return Response::json([
            'check' => $check->uuid,
            'status' => $check->refresh()->status?->value,
            'result' => $result === null ? null : [
                'status' => $result->status->value,
                'message' => $result->message,
                'duration_us' => $result->durationUs,
                'metrics' => $result->metrics,
            ],
        ]);
    }
}
