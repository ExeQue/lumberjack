<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\DuplicateScreenAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Screens, McpLevel::Write)]
class DuplicateScreen extends Tool
{
    use UsesScreens;

    protected string $name = 'duplicate_screen';

    protected string $description = 'Copies a screen under a new uuid, not shared. Returns the copy\'s uuid and name.';

    public function __construct(
        private readonly DuplicateScreenAction $duplicate,
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
        $screen = $this->screen($request, 'update');

        if ($screen instanceof Response) {
            return $screen;
        }

        $copy = $this->duplicate->execute($screen);

        return Response::json(['screen' => $copy->uuid, 'name' => $copy->name]);
    }
}
