<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\SetScreenShareAction;
use App\Domains\Screens\Http\Requests\UpdateScreenShareRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * The share link stays out of the answer: the designer shows it, but an agent turning
 * sharing on does not need the credential.
 */
#[RequiresPermission(McpArea::Screens, McpLevel::Manage, makesPublic: true)]
class SetScreenShare extends Tool
{
    use UsesScreens;

    protected string $name = 'set_screen_share';

    protected string $description = 'Turns a screen\'s public share link on or off, and whether it shows titles and names or only counts. Returns whether it is shared; the link itself is in the designer.';

    public function __construct(
        private readonly SetScreenShareAction $share,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'screen' => $schema->string()->description('The screen\'s uuid; see list_screens.')->required(),
            'enabled' => $schema->boolean()->description('True for a share link anyone can open, false to stop it working.')->required(),
            'include_titles' => $schema->boolean()->description('Show issue titles and names, not only counts. Default false.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $screen = $this->screen($request, 'share');

        if ($screen instanceof Response) {
            return $screen;
        }

        $input = $request->validate((new UpdateScreenShareRequest())->rules());
        /** @var User $user */
        $user = $this->context()->token()->user;
        $screen = $this->share->execute($screen, $user, (bool) $input['enabled'], (bool) ($input['include_titles'] ?? false));

        return Response::json(['screen' => $screen->uuid, 'shared' => $screen->share_key_hash !== null, 'include_titles' => $screen->share_titles]);
    }
}
