<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\ExportScreenAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The screen file the web Export downloads. A screen with a Wi-Fi password or a media link
 * is only reached by a token covering every project (UsesScreens::screen()).
 */
#[IsReadOnly]
#[RequiresPermission(McpArea::Screens, McpLevel::Read)]
class ExportScreen extends Tool
{
    use UsesScreens;

    protected string $name = 'export_screen';

    protected string $description = 'Exports a screen as its portable JSON file (grid, settings, widgets; no share link or displays), the input of import_screen.';

    public function __construct(
        private readonly ExportScreenAction $export,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'screen' => $schema->string()->description('The screen\'s uuid; see list_screens.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $screen = $this->screen($request);

        return $screen instanceof Response ? $screen : Response::json($this->export->execute($screen));
    }
}
