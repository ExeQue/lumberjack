<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\RequestReloadAction;
use App\Domains\Screens\Exceptions\ReloadTooSoonException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Screens, McpLevel::Write)]
class ReloadScreen extends Tool
{
    use UsesScreens;

    protected string $name = 'reload_screen';

    protected string $description = 'Makes every kiosk, paired TV and share link showing a screen reload its page; at most once every few seconds. Returns how many heard it, null when unknown.';

    public function __construct(
        private readonly RequestReloadAction $reload,
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

        try {
            return Response::json(['screen' => $screen->uuid, 'reloaded' => $this->reload->execute($screen)]);
        } catch (ReloadTooSoonException $e) {
            return Response::error($e->getMessage());
        }
    }
}
