<?php

declare(strict_types=1);

namespace App\Domains\Logs\Http\Controllers;

use App\Domains\Logs\Actions\CreateLogChannelAction;
use App\Domains\Logs\Actions\DeleteLogChannelAction;
use App\Domains\Logs\Exceptions\LogChannelExceptionInterface;
use App\Domains\Logs\Http\Requests\StoreLogChannelRequest;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LogChannelController
{
    public function store(StoreLogChannelRequest $request, Project $project, CreateLogChannelAction $action): RedirectResponse
    {
        $action->execute($project, $request->string('name')->toString());

        return back()->with('status', __('logs.channel_created'));
    }

    public function destroy(Request $request, Project $project, LogChannel $logChannel, DeleteLogChannelAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        try {
            $action->execute($logChannel);
        } catch (LogChannelExceptionInterface $e) {
            return back()->withErrors(['channel' => $e->getMessage()]);
        }

        return back()->with('status', __('logs.channel_deleted'));
    }
}
