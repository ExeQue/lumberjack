<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\CountScreenViewersAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Screens, McpLevel::Read)]
class ListScreenViewers extends Tool
{
    use UsesScreens;

    protected string $name = 'list_screen_viewers';

    protected string $description = 'How many kiosks, paired TVs and share links have a screen open right now; null when that cannot be known.';

    public function __construct(
        private readonly CountScreenViewersAction $viewers,
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

        return $screen instanceof Response ? $screen : Response::json(['screen' => $screen->uuid, 'viewers' => $this->viewers->execute($screen)]);
    }
}
