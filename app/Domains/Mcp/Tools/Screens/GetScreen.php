<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Presenters\ScreenPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetScreen extends Tool
{
    use UsesScreens;

    protected string $name = 'get_screen';

    protected string $description = 'One screen: its settings, grid, every widget and its revision.';

    public function __construct(
        private readonly ScreenPresenter $presenter,
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
        $screen = $this->screen($request);

        return $screen instanceof Response ? $screen : Response::json($this->presenter->present($screen));
    }
}
