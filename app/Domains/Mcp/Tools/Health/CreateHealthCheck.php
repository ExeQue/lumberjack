<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Health;

use App\Domains\Health\Actions\SaveHealthCheckAction;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Http\Requests\SaveHealthCheckRequest;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesHealthChecks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Health, McpLevel::Write)]
class CreateHealthCheck extends Tool
{
    use UsesHealthChecks;

    protected string $name = 'create_health_check';

    protected string $description = 'Adds a check Lumberjack runs: http, ssl, tcp, dns, heartbeat, domain (expiry) or smtp. Pass the type, interval and thresholds, and the type\'s settings under config; returns the new check. A heartbeat\'s ping URL is a secret: find it on the Health page.';

    public function __construct(
        private readonly SaveHealthCheckAction $save,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'type' => $schema->string()->enum(array_column(HealthCheckType::cases(), 'value'))->required(),
            ...$this->settingsSchema($schema),
            'interval_minutes' => $schema->integer()->enum(HealthCheck::INTERVALS)->description('How often it runs.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $denied = $this->authorize('update', $project);

        if ($denied !== null) {
            return $denied;
        }

        $input = $request->all();
        $input['degrade_after'] ??= HealthCheck::DEFAULT_DEGRADE_AFTER;
        $input['down_after'] ??= HealthCheck::DEFAULT_DOWN_AFTER;
        $input['recover_after'] ??= HealthCheck::DEFAULT_RECOVER_AFTER;
        $type = HealthCheckType::tryFrom((string) $request->get('type'));

        $validated = Validator::make($input, SaveHealthCheckRequest::rulesFor(null, $type))
            ->after(SaveHealthCheckRequest::limitsFor($project, null))
            ->validate();

        $check = $this->save->execute($project, null, $validated, SaveHealthCheckRequest::configFrom($validated, $type));

        return Response::json(['check' => $this->presentCheck($check->refresh())]);
    }
}
