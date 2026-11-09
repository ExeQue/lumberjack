<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Support\ScreenAccess;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Actions\ResolveScreenDataAction;
use App\Domains\Screens\Http\Requests\ViewerDataRequest;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Presenters\ScreenPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed look at a screen that an MCP token asked for: no session, gone after minutes
 * or as soon as the token stops or no longer covers the screen. Nothing broadcasts to it.
 */
class ScreenPreviewController
{
    private const array HEADERS = [
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store',
        'X-Robots-Tag' => 'noindex',
    ];

    public function __construct(
        private readonly ScreenAccess $access,
    ) {
    }

    public function show(Request $request, Screen $screen, ScreenPresenter $presenter): Response
    {
        $token = $this->token($request, $screen);
        $data = URL::temporarySignedRoute('screens.preview.data', CarbonImmutable::createFromTimestamp($request->integer('expires')), ['screen' => $screen->uuid, 'token' => $token->id], absolute: false);

        $response = Inertia::render('screens/kiosk', [
            ...$presenter->kiosk($screen, ['data_url' => $data, 'channel' => 'screen-preview.'.Str::random(32), 'share' => true]),
            'projects' => $token->readableProjects()->map(fn (Project $p): array => ['uuid' => $p->uuid, 'name' => $p->name])->all(),
        ])->toResponse($request);
        $response->headers->add(self::HEADERS);

        return $response;
    }

    public function data(ViewerDataRequest $request, Screen $screen, ResolveScreenDataAction $action): JsonResponse
    {
        $this->token($request, $screen);

        return response()->json([
            'data' => (object) $action->execute($screen, $request->widget(), aggregatesOnly: false),
            'at' => now()->toIso8601String(),
        ])->withHeaders(self::HEADERS);
    }

    private function token(Request $request, Screen $screen): McpToken
    {
        abort_unless(URL::hasValidSignature($request, false, ['widget']), 403);
        $token = McpToken::query()->find($request->integer('token'));
        abort_unless($token !== null && ! $token->isExpired() && $token->team_id === $screen->team_id && $token->allows('view', $screen) && $this->access->covers($token, $screen), 403);

        return $token;
    }
}
