<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Team settings > Displays, without pairing links or credentials. A screen the token may
 * not see shows as "hidden".
 */
#[IsReadOnly]
#[RequiresPermission(McpArea::Screens, McpLevel::Read)]
class ListDisplays extends Tool
{
    use UsesScreens;

    protected string $name = 'list_displays';

    protected string $description = 'The team\'s paired devices and their display windows: name, which screens each shows, and whether it is online.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $token = $this->context()->token();
        $uuids = Screen::query()->where('team_id', $token->team_id)->with('team')->get()
            ->mapWithKeys(fn (Screen $s): array => [$s->id => $token->allows('view', $s) && $this->access()->covers($token, $s) ? $s->uuid : 'hidden']);

        $devices = Device::query()->whereTeam($token->team_id)->withDisplays()->orderBy('name')->get()
            ->map(fn (Device $device): array => [
                'uuid' => $device->uuid,
                'name' => $device->name,
                'online' => $device->isOnline(),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'displays' => $device->displays->map(fn (Display $d): array => [
                    'uuid' => $d->uuid,
                    'name' => $d->label(),
                    'screens' => array_values(array_filter(array_map(fn (int $id): ?string => $uuids->get($id), $d->screenIds()))),
                    'online' => $d->isOnline(),
                    'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                ])->values()->all(),
            ]);

        return Response::json(['devices' => $devices->values()->all()]);
    }
}
