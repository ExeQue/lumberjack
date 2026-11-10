<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\ImportScreenAction;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Screens, McpLevel::Manage)]
class ImportScreen extends Tool
{
    use UsesScreens;

    protected string $name = 'import_screen';

    protected string $description = 'Creates a screen from a screen file (the JSON export_screen returns), passed as a string. Returns the new screen and what the team had no match for.';

    public function __construct(
        private readonly ImportScreenAction $import,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'json' => $schema->string()->description('The screen file as JSON text.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $input = $request->validate(['json' => ['required', 'string', 'max:'.(ScreenFile::MAX_KILOBYTES * 1024)]]);
        $document = json_decode($input['json'], true, 64);

        if (! is_array($document) || array_is_list($document)) {
            return Response::error(__('ui/screens.import.errors.json'));
        }

        $token = $this->context()->token();
        $team = $token->team;

        if (! $token->allows('create', [Screen::class, $team])) {
            return Response::error('The token\'s owner may not create screens.');
        }

        $read = $this->import->read($team, $document);

        if (! $this->access()->coversDesign($token, $team, $read->attributes['scope'], $read->attributes['widgets'])) {
            return $this->outsideToken();
        }

        $import = $this->import->execute($team, $document);

        return Response::json([
            'screen' => $import->screen?->uuid,
            'name' => $import->screen?->name,
            'dropped_projects' => $import->droppedProjects,
            'dropped_checks' => $import->droppedChecks,
        ]);
    }
}
