<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\DeleteScreenAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[RequiresPermission(McpArea::Screens, McpLevel::Manage)]
class DeleteScreen extends Tool
{
    use UsesScreens;

    protected string $name = 'delete_screen';

    protected string $description = 'Deletes a screen for good; TVs showing it say it was deleted.';

    public function __construct(
        private readonly DeleteScreenAction $delete,
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
        $screen = $this->screen($request, 'delete');

        if ($screen instanceof Response) {
            return $screen;
        }

        $this->delete->execute($screen);

        return Response::json(['screen' => $screen->uuid, 'deleted' => true]);
    }
}
