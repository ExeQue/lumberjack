<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Health;

use App\Domains\Health\Actions\SaveHealthCheckAction;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[RequiresPermission(McpArea::Health, McpLevel::Write)]
class UpdateHealthCheck extends Tool
{
    use UsesHealthChecks;

    protected string $name = 'update_health_check';

    protected string $description = 'Changes a health check; pass only what changes, config keys are merged into the current ones (null clears one). A check the app reports only takes name, description, muted and thresholds. Returns the check.';

    public function __construct(
        private readonly SaveHealthCheckAction $save,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'check' => $this->checkArgument($schema),
            ...$this->settingsSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $check = $this->healthCheck($request, 'update');

        if ($check instanceof Response) {
            return $check;
        }

        $input = [...$this->current($check), ...$request->all()];
        $given = (array) $request->get('config', []);

        // A url sent back as get_health_check showed it (masked) keeps the stored one.
        if (isset($given['url']) && $given['url'] === ($this->presentCheck($check)['config']['url'] ?? null)) {
            unset($given['url']);
        } elseif (is_string($given['url'] ?? null) && str_contains($given['url'], '***')) {
            return Response::error('The url still has a masked (***) value. Send the url exactly as get_health_check showed it to keep it, or the full new url.');
        }

        $input['config'] = [...($check->config ?? []), ...$given];

        $validated = Validator::make($input, SaveHealthCheckRequest::rulesFor($check, $check->type))
            ->after(SaveHealthCheckRequest::limitsFor($check->project, $check))
            ->validate();

        $this->save->execute($check->project, $check, $validated, SaveHealthCheckRequest::configFrom($validated, $check->type));

        return Response::json(['check' => $this->presentCheck($check->refresh())]);
    }

    /**
     * The check's settings as the edit form would send them, so what is not passed stays.
     * A reported check's thresholds are only sent when they were set here, so they keep following the app.
     *
     * @return array<string, mixed>
     */
    private function current(HealthCheck $check): array
    {
        $thresholds = ! $check->isReported() || $check->thresholds_overridden
            ? ['degrade_after' => $check->degrade_after, 'down_after' => $check->down_after ?? $check->downAfter(), 'recover_after' => $check->recover_after]
            : [];

        return [
            'name' => $check->name,
            'description' => $check->description,
            'muted' => $check->muted,
            ...$thresholds,
            ...($check->isReported() ? [] : ['environment' => $check->environment, 'interval_minutes' => $check->interval_minutes]),
        ];
    }
}
