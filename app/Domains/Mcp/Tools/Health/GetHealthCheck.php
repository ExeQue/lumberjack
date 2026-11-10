<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Health;

use App\Domains\Health\Http\Controllers\HealthController;
use App\Domains\Health\Models\HealthCheckResult;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesHealthChecks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Health, McpLevel::Read)]
class GetHealthCheck extends Tool
{
    use UsesHealthChecks;

    protected string $name = 'get_health_check';

    protected string $description = 'One health check with its settings (type, interval, thresholds, config) and its latest results, newest first. A heartbeat\'s ping URL is a secret and is not returned; it is on the Health page.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'check' => $this->checkArgument($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $check = $this->healthCheck($request);

        if ($check instanceof Response) {
            return $check;
        }

        return Response::json([
            'check' => $this->presentCheck($check),
            'results' => $check->results()->latest('checked_at')->limit(HealthController::RECENT_RESULTS)->get()->map(fn (HealthCheckResult $r): array => [
                'host' => $r->host !== '' ? $r->host : null,
                'status' => $r->status->value,
                'message' => $r->message,
                'duration_us' => $r->duration_us,
                'metrics' => $r->metrics ?? [],
                'checked_at' => $r->checked_at->toIso8601String(),
            ])->all(),
        ]);
    }
}
