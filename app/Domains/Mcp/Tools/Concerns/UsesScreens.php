<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Concerns;

use App\Domains\Mcp\Support\ScreenAccess;
use App\Domains\Screens\Exceptions\ScreenExceptionInterface;
use App\Domains\Screens\Models\Screen;
use Closure;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait UsesScreens
{
    use UsesMcpContext;

    protected function access(): ScreenAccess
    {
        return app(ScreenAccess::class);
    }

    /**
     * The screen the request names, if the token may do $ability on it and covers
     * every project it shows.
     */
    protected function screen(Request $request, string $ability = 'view'): Screen|Response
    {
        $uuid = $request->get('screen');
        $token = $this->context()->token();
        $screen = is_string($uuid) && Str::isUuid($uuid) ? Screen::query()->where('team_id', $token->team_id)->where('uuid', $uuid)->first() : null;

        if ($screen === null) {
            return Response::error('Unknown screen for this token; see list_screens.');
        }

        if (! $token->allows($ability, $screen)) {
            return Response::error('The token\'s owner may not '.($ability === 'view' ? 'see' : 'change').' this screen.');
        }

        return $this->access()->covers($token, $screen)
            ? $screen
            : Response::error('This screen shows projects the token does not cover.');
    }

    protected function outsideToken(): Response
    {
        return Response::error('That would show projects the token does not cover.');
    }

    /**
     * Runs a change and answers with the screen's new revision.
     *
     * @param  Closure(): Screen  $change
     */
    protected function saved(Closure $change): Response
    {
        try {
            $screen = $change();
        } catch (ScreenExceptionInterface $e) {
            return Response::error($e->getMessage());
        }

        return Response::json(['screen' => $screen->uuid, 'revision' => $screen->revision]);
    }
}
