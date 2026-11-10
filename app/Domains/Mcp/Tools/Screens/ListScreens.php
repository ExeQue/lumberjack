<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Models\Screen;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Screens, McpLevel::Read)]
class ListScreens extends Tool
{
    use UsesScreens;

    protected string $name = 'list_screens';

    protected string $description = 'The team screens this token may see, with the revision to pass when changing one.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $token = $this->context()->token();
        $screens = Screen::query()->where('team_id', $token->team_id)->orderBy('name')->with('team')->get()
            ->filter(fn (Screen $screen): bool => $token->allows('view', $screen) && $this->access()->covers($token, $screen))
            ->map(fn (Screen $screen): array => ['uuid' => $screen->uuid, 'name' => $screen->name, 'cols' => $screen->cols, 'rows' => $screen->rows, 'widgets' => count($screen->widgets), 'revision' => $screen->revision, 'updated_at' => $screen->updated_at?->toIso8601String()]);

        return Response::json(['screens' => $screens->values()->all()]);
    }
}
