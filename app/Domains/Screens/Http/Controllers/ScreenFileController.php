<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\CopyScreenToTeamAction;
use App\Domains\Screens\Actions\ExportScreenAction;
use App\Domains\Screens\Actions\ImportScreenAction;
use App\Domains\Screens\Http\Requests\CopyScreenRequest;
use App\Domains\Screens\Http\Requests\ImportScreenRequest;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenFile;
use App\Domains\Teams\Models\Team;
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

    /** Copy to another team of the user's: the export imported there, in one step. */
    public function copy(CopyScreenRequest $request, Screen $screen, CopyScreenToTeamAction $action): RedirectResponse
    {
        /** @var Team $team authorized */
        $team = $request->team();
        $import = $action->execute($screen, $team);

        return back()->with('status', trim(__('screens.copied', ['name' => $screen->name, 'team' => $team->name]).' '.$import->droppedNotice()));
    }
}
