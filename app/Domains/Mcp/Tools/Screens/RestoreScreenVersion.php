<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Screens;

use App\Domains\Mcp\Tools\Concerns\UsesScreens;
use App\Domains\Screens\Actions\RestoreScreenVersionAction;
use App\Domains\Screens\Models\ScreenVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class RestoreScreenVersion extends Tool
{
    use UsesScreens;

    protected string $name = 'restore_screen_version';

    protected string $description = 'Puts a screen back as it was, live on every TV showing it; what it is now becomes a version in turn. Needs a token that may design screens.';

    public function __construct(
        private readonly RestoreScreenVersionAction $restore,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'screen' => $schema->string()->description('The screen\'s uuid; see list_screens.')->required(),
            'revision' => $schema->integer()->description('The revision you read from get_screen. A stale one is refused.')->required(),
            'version' => $schema->string()->description('A uuid from list_screen_versions.')->required(),
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

        $input = $request->validate(['version' => ['required', 'string'], 'revision' => ['required', 'integer', 'min:0']]);
        $version = Str::isUuid($input['version']) ? ScreenVersion::query()->whereBelongsTo($screen)->where('uuid', $input['version'])->first() : null;

        if ($version === null) {
            return Response::error('Unknown version of this screen; see list_screen_versions.');
        }

        if (! $this->access()->coversDesign($this->context()->token(), $screen->team, (array) ($version->snapshot['scope'] ?? []), (array) ($version->snapshot['widgets'] ?? []))) {
            return $this->outsideToken();
        }

        return $this->saved(fn () => $this->restore->execute($screen, $version, $this->context()->token(), (int) $input['revision']));
    }
}
