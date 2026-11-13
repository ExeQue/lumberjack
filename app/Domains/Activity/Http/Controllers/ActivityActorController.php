<?php

declare(strict_types=1);

namespace App\Domains\Activity\Http\Controllers;

use App\Domains\Activity\Actions\ForgetActivityActorAction;
use App\Domains\Activity\Http\Requests\ForgetActivityActorRequest;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Forgets every event of one actor, such as when the account behind it is deleted.
 */
class ActivityActorController
{
    /**
     * From the app, with its server token.
     */
    public function destroy(ForgetActivityActorRequest $request, ForgetActivityActorAction $action): JsonResponse
    {
        return response()->json(['deleted' => $action->execute($request->project(), $request->string('type')->toString(), $request->string('id')->toString())]);
    }

    /**
     * From the event page.
     */
    public function forget(ForgetActivityActorRequest $request, Project $project, ForgetActivityActorAction $action): RedirectResponse
    {
        $deleted = $action->execute($project, $request->string('type')->toString(), $request->string('id')->toString());

        return back()->with('status', trans_choice('activity.forgotten', $deleted, ['count' => $deleted]));
    }
}
