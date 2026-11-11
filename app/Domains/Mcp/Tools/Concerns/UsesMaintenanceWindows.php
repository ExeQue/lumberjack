<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Concerns;

use App\Domains\Maintenance\Actions\SaveMaintenanceWindowAction;
use App\Domains\Maintenance\Http\Requests\SaveMaintenanceWindowRequest;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait UsesMaintenanceWindows
{
    use UsesMcpContext;

    protected function windowArgument(JsonSchema $schema): mixed
    {
        return $schema->string()->description('The window\'s id; see list_maintenance_windows.')->required();
    }

    /**
     * The window the request names within its project, if the token's maker may do $ability on it.
     */
    protected function maintenanceWindow(Request $request, string $ability = 'view'): MaintenanceWindow|Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $uuid = $request->get('window');
        $window = is_string($uuid) && Str::isUuid($uuid) ? $project->maintenanceWindows()->where('uuid', $uuid)->first() : null;

        if ($window === null) {
            return Response::error('Unknown maintenance window in project '.$project->slug.'; see list_maintenance_windows.');
        }

        return $this->authorize($ability, $window) ?? $window;
    }

    /**
     * The settings a planned window takes.
     *
     * @return array<string, mixed>
     */
    protected function windowSchema(JsonSchema $schema): array
    {
        return [
            'environment' => $schema->string()->description('The environment it covers, e.g. production. Empty for every environment.'),
            'starts_at' => $schema->string()->description('ISO 8601 date and time.'),
            'ends_at' => $schema->string()->description('ISO 8601, after starts_at. The expected end: past it, the maintenance overrun alert fires.'),
            'message' => $schema->string()->description('Shown with the window and in alerts, up to 500 characters.'),
        ];
    }

    /**
     * Validates the input with the web form's rules and saves the window.
     *
     * @param  array<string, mixed>  $input
     */
    protected function saveWindow(SaveMaintenanceWindowAction $save, Project $project, ?MaintenanceWindow $window, array $input): MaintenanceWindow
    {
        // The form request parses the dates the way the web form does.
        $form = new SaveMaintenanceWindowRequest(Validator::make($input, SaveMaintenanceWindowRequest::rulesFor())->validate());

        return $save->execute($project, $window, $form->string('environment')->toString(), $form->startsAt(), $form->endsAt(), $form->filled('message') ? $form->string('message')->toString() : null);
    }
}
