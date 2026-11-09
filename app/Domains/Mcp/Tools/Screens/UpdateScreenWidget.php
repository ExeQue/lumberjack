<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\UpdateScreenWidgetAction;
use App\Domains\Screens\Support\WidgetSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdateScreenWidget extends Tool
{
    use UsesScreens;

    protected string $name = 'update_screen_widget';

    protected string $description = 'Changes one widget on its cells, live on every TV showing it; send the whole widget. Needs a token that may design screens.';

    public function __construct(
        private readonly UpdateScreenWidgetAction $update,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'screen' => $schema->string()->description('The screen\'s uuid; see list_screens.')->required(),
            'revision' => $schema->integer()->description('The revision you read from get_screen. A stale one is refused.')->required(),
            'id' => $schema->string()->description('The widget\'s id.')->required(),
            'widget' => $schema->object()->description('{type, source, x, y, w, h, title, options, scope}; see get_screen_design_guide.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessDesign()) {
            return $denied;
        }

        $screen = $this->screen($request, 'update');

        if ($screen instanceof Response) {
            return $screen;
        }

        $id = $request->validate(['id' => ['required', 'string', 'max:40']])['id'];
        $widget = [...(array) $request->get('widget'), 'id' => $id];
        $projects = array_values($screen->team->projects()->pluck('uuid')->map(fn (mixed $uuid): string => (string) $uuid)->all());
        Validator::make(['widget' => $widget, 'revision' => $request->get('revision')], [
            ...WidgetSchema::rules('widget', $widget, $screen->cols, $screen->rows, $projects),
            'revision' => ['required', 'integer', 'min:0'],
        ])->validate();

        $others = array_filter($screen->widgets, fn (array $existing): bool => $existing['id'] !== $id);

        if (! $this->access()->coversDesign($this->context()->token(), $screen->team, $screen->scope, [...$others, WidgetSchema::clean($widget, $screen->cols, $screen->rows)])) {
            return $this->outsideToken();
        }

        return $this->saved(fn () => $this->update->execute($screen, $id, $widget, $this->context()->token(), (int) $request->get('revision')));
    }
}
