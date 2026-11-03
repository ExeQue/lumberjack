<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\RotateScreenShareKeyAction;
use App\Domains\Screens\Actions\SetScreenShareAction;
use App\Domains\Screens\Http\Requests\UpdateScreenShareRequest;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A screen's share link, from the designer: on and off, titles or only counts, a new key,
 * and the link's QR code for printing.
 */
class ScreenShareController
{
    public function update(UpdateScreenShareRequest $request, Screen $screen, SetScreenShareAction $action): RedirectResponse
    {
        $action->execute($screen, $request->user(), $request->boolean('enabled'), $request->boolean('include_titles'));

        return back()->with('status', __($screen->share_key !== null ? 'screens.share.saved' : 'screens.share.revoked'));
    }

    public function rotate(Request $request, Screen $screen, RotateScreenShareKeyAction $action): RedirectResponse
    {
        Gate::authorize('share', $screen);
        $action->execute($screen, $request->user());

        return back()->with('status', __('screens.share.rotated'));
    }

    /** The share link's QR code, dark on white, as PNG for a sticker. */
    public function qr(Screen $screen): Response
    {
        Gate::authorize('view', $screen);
        $url = $screen->shareUrl();
        abort_if($url === null, 404);

        return ScreenQr::printPng($url)->download('lumberjack-'.Str::slug($screen->name).'-qr.png');
    }
}
