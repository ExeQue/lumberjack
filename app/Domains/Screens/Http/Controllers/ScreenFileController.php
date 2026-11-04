<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\ExportScreenAction;
use App\Domains\Screens\Actions\ImportScreenAction;
use App\Domains\Screens\Http\Requests\ImportScreenRequest;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** A screen to and from its portable file (ScreenFile). See docs/screens.md. */
class ScreenFileController
{
    public function export(Screen $screen, ExportScreenAction $action): JsonResponse
    {
        Gate::authorize('view', $screen);

        return response()->json(
            $action->execute($screen),
            200,
            ['Content-Disposition' => 'attachment; filename="'.ScreenFile::fileName($screen->name).'"'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** What importing the file would create, without creating it. */
    public function preview(ImportScreenRequest $request, ImportScreenAction $action): JsonResponse
    {
        return response()->json($action->read($request->team(), $request->document())->toPreview());
    }

    public function import(ImportScreenRequest $request, ImportScreenAction $action): RedirectResponse
    {
        $import = $action->execute($request->team(), $request->document());

        return redirect()->route('screens.edit', $import->screen)
            ->with('status', trim(__('screens.imported').' '.$import->droppedNotice()));
    }
}
