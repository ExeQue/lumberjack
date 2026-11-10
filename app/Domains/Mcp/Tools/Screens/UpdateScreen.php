<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\UpdateScreenAction;
use App\Domains\Screens\Http\Requests\UpdateScreenRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Screens, McpLevel::Write)]
class UpdateScreen extends Tool
{
    use UsesScreens;

    protected string $name = 'update_screen';

    protected string $description = 'Changes a screen settings, live on every TV showing it; pass only what changes.';

    public function __construct(
        private readonly UpdateScreenAction $update,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'screen' => $schema->string()->description('The screen\'s uuid; see list_screens.')->required(),
            'revision' => $schema->integer()->description('The revision you read from get_screen. A stale one is refused.')->required(),
            'name' => $schema->string(),
            'theme' => $schema->string()->description('See get_screen_design_guide.'),
            'aspect' => $schema->string(),
            'orientation' => $schema->string(),
            'fit' => $schema->string(),
            'show_header' => $schema->boolean(),
            'show_clock' => $schema->boolean(),
            'clock_format' => $schema->string()->description('24 or 12.'),
            'light_from' => $schema->string()->description('HH:MM.'),
            'light_to' => $schema->string()->description('HH:MM.'),
            'timezone' => $schema->string()->description('Null: each viewer\'s own.'),
            'scope' => $schema->object()->description('{projects: [uuid], environment, period}. No projects means every project.'),
        ];
    }

    public function handle(Request $request): Response
    {

        $screen = $this->screen($request, 'update');

        if ($screen instanceof Response) {
            return $screen;
        }

        $input = $request->validate([...UpdateScreenRequest::rulesFor($screen), 'revision' => ['required', 'integer', 'min:0']]);
        $settings = UpdateScreenRequest::settingsFrom($input);

        if (! $this->access()->coversDesign($this->context()->token(), $screen->team, $settings['scope'] ?? $screen->scope, $screen->widgets)) {
            return $this->outsideToken();
        }

        return $this->saved(fn () => $this->update->execute($screen, $settings, $this->context()->token(), (int) $input['revision']));
    }
}
