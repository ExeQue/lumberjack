<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\UpdateDisplayAction;
use App\Domains\Screens\Models\Display;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Screens, McpLevel::Manage)]
class AssignDisplay extends Tool
{
    use UsesScreens;

    protected string $name = 'assign_display';

    protected string $description = 'Sets which screen a paired display shows, replacing any playlist; the TV switches at once. Pass the display from list_displays and a screen, or null to show none.';

    public function __construct(
        private readonly UpdateDisplayAction $update,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'display' => $schema->string()->description('The display\'s uuid; see list_displays.')->required(),
            'screen' => $schema->string()->description('The screen\'s uuid; see list_screens. Null to show none.')->nullable()->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['display' => ['required', 'uuid'], 'screen' => ['present', 'nullable', 'uuid']]);
        $token = $this->context()->token();
        $uuid = (string) $request->get('display');
        $display = Str::isUuid($uuid) ? Display::query()->where('team_id', $token->team_id)->where('uuid', $uuid)->first() : null;

        if ($display === null) {
            return Response::error('Unknown display for this token; see list_displays.');
        }

        $denied = $this->authorize('update', $display);

        if ($denied !== null) {
            return $denied;
        }

        $screen = $request->get('screen') === null ? null : $this->screen($request);

        if ($screen instanceof Response) {
            return $screen;
        }

        $this->update->execute($display, ['screen_id' => $screen?->id]);

        return Response::json(['display' => $display->uuid, 'screen' => $screen?->uuid]);
    }
}
