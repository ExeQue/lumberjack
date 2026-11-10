<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\UpdateScreenLayoutAction;
use App\Domains\Screens\Http\Requests\UpdateScreenLayoutRequest;
use App\Domains\Screens\Support\WidgetSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Screens, McpLevel::Write)]
class UpdateScreenLayout extends Tool
{
    use UsesScreens;

    protected string $name = 'update_screen_layout';

    protected string $description = 'Replaces a screen grid and all its widgets, live on every TV showing it. Send every widget you want to keep. Widgets may not overlap.';

    public function __construct(
        private readonly UpdateScreenLayoutAction $update,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'screen' => $schema->string()->description('The screen\'s uuid; see list_screens.')->required(),
            'revision' => $schema->integer()->description('The revision you read from get_screen. A stale one is refused.')->required(),
            'cols' => $schema->integer()->required(),
            'rows' => $schema->integer()->required(),
            'widgets' => $schema->array()->items($schema->object())->description('Each {id, type, source, x, y, w, h, title, options, scope}; see get_screen_design_guide.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {

        $screen = $this->screen($request, 'update');

        if ($screen instanceof Response) {
            return $screen;
        }

        $input = $request->validate([...UpdateScreenLayoutRequest::rulesFor($screen, $request->all()), 'revision' => ['required', 'integer', 'min:0']]);
        $given = array_values((array) $request->get('widgets'));
        $ids = array_column($given, 'id');

        if (count($ids) !== count(array_unique($ids))) {
            return Response::error('Each widget needs its own id.');
        }

        $cols = (int) $input['cols'];
        $rows = (int) $input['rows'];
        $widgets = array_map(fn (array $widget): array => WidgetSchema::clean($widget, $cols, $rows), $given);

        if (! $this->access()->coversDesign($this->context()->token(), $screen->team, $screen->scope, $widgets)) {
            return $this->outsideToken();
        }

        return $this->saved(fn () => $this->update->execute($screen, $cols, $rows, $given, $this->context()->token(), (int) $input['revision']));
    }
}
