<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\URL;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Screens, McpLevel::Read)]
class PreviewScreen extends Tool
{
    use UsesScreens;

    protected string $name = 'preview_screen';

    protected string $description = 'A link to the screen as a TV shows it, to open in a browser and size as the TV. It works without signing in and expires after 5 minutes.';

    private const int MINUTES = 5;

    public function schema(JsonSchema $schema): array
    {
        return [
            'screen' => $schema->string()->description('The screen\'s uuid; see list_screens.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $screen = $this->screen($request);

        if ($screen instanceof Response) {
            return $screen;
        }

        $expires = now()->addMinutes(self::MINUTES);

        return Response::json([
            'url' => url(URL::temporarySignedRoute('screens.preview', $expires, ['screen' => $screen->uuid, 'token' => $this->context()->token()->id], absolute: false)),
            'expires_at' => $expires->toIso8601String(),
        ]);
    }
}
