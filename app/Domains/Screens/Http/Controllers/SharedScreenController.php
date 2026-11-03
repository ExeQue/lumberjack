<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\ResolveScreenDataAction;
use App\Domains\Screens\Broadcasting\ScreenShareChannel;
use App\Domains\Screens\Http\Requests\ViewerDataRequest;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Presenters\ScreenPresenter;
use App\Domains\Screens\Support\TvCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * /s/{key}: a screen by its share link, without a session, read only. Counts and
 * aggregates only, unless the team chose to show titles and names too. It may be framed
 * (signage systems embed a URL), and sends no Referer, so the key stays here.
 */
class SharedScreenController
{
    public const array HEADERS = [
        'Content-Security-Policy' => 'frame-ancestors *',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store',
    ];

    public function show(Request $request, string $key, ScreenPresenter $presenter): Response
    {
        $screen = $this->screen($key);

        $page = $screen === null
            ? Inertia::render('screens/unshared')
            : Inertia::render('screens/kiosk', $presenter->kiosk($screen, [
                'data_url' => route('screens.shared.data', $key, absolute: false),
                'channel' => ScreenShareChannel::name((string) $screen->share_key_hash),
                'share' => true,
            ]));

        $response = $page->toResponse($request)->setStatusCode($screen === null ? 404 : 200);
        $response->headers->add(self::HEADERS);

        return $response;
    }

    public function data(ViewerDataRequest $request, string $key, ResolveScreenDataAction $action): JsonResponse
    {
        $screen = $this->screen($key);
        abort_if($screen === null, 404);

        return response()->json([
            'data' => (object) $action->execute($screen, $request->widget(), aggregatesOnly: ! $screen->share_titles),
            'at' => now()->toIso8601String(),
        ])->withHeaders(self::HEADERS);
    }

    private function screen(string $key): ?Screen
    {
        $hash = TvCredentials::hash($key);
        $screen = Screen::query()->where('share_key_hash', $hash)->first();

        return $screen !== null && hash_equals((string) $screen->share_key_hash, $hash) ? $screen : null;
    }
}
