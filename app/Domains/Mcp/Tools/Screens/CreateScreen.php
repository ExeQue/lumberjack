<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\CreateScreenAction;
use App\Domains\Screens\Enums\ScreenTemplate;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Presenters\ScreenPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Screens, McpLevel::Write)]
class CreateScreen extends Tool
{
    use UsesScreens;

    protected string $name = 'create_screen';

    protected string $description = 'Creates a screen, empty or from a template. A template shows every project, so it needs a token covering them all.';

    public function __construct(
        private readonly CreateScreenAction $create,
        private readonly ScreenPresenter $presenter,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Up to 100 characters.')->required(),
            'template' => $schema->string()->enum(array_column(ScreenTemplate::cases(), 'value'))->description('Default blank.'),
        ];
    }

    public function handle(Request $request): Response
    {

        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'template' => ['nullable', Rule::enum(ScreenTemplate::class)],
        ]);
        $template = ScreenTemplate::from($input['template'] ?? ScreenTemplate::Blank->value);
        $token = $this->context()->token();
        $team = $token->team;

        if (! $token->allows('create', [Screen::class, $team])) {
            return Response::error('The token\'s owner may not create screens.');
        }

        if (! $this->access()->coversDesign($token, $team, [], $template->widgets())) {
            return $this->outsideToken();
        }

        return Response::json($this->presenter->present($this->create->execute($team, $input['name'], $template)));
    }
}
