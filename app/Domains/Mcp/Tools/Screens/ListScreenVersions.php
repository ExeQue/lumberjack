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
class ListScreenVersions extends Tool
{
    use UsesScreens;

    protected string $name = 'list_screen_versions';

    protected string $description = 'What a screen can go back to with restore_screen_version, newest first, each with who replaced it.';

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

        return $screen instanceof Response ? $screen : Response::json(['versions' => $this->presenter->versions($screen)]);
    }
}
