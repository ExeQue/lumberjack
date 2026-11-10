<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Http\Controllers;

use App\Domains\Mcp\Actions\CreateMcpTokenAction;
use App\Domains\Mcp\Actions\UpdateMcpTokenAction;
use App\Domains\Mcp\Http\Requests\StoreMcpTokenRequest;
use App\Domains\Mcp\Http\Requests\UpdateMcpTokenRequest;
use App\Domains\Mcp\Models\McpToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class McpTokenController
{
    public function store(StoreMcpTokenRequest $request, CreateMcpTokenAction $action): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        $days = $request->input('expires_in_days');

        ['plain' => $plain] = $action->execute(
            $team,
            $request->user(),
            $request->string('name')->toString(),
            $request->boolean('all_projects'),
            $request->projectIds($team),
            $request->permissions(),
            $days !== null ? now()->addDays((int) $days) : null,
            $request->changes($team),
        );

        return back()->with('secret', ['label' => __('mcp.token_created', ['name' => $request->string('name')->toString()]), 'value' => $plain]);
    }

    public function update(UpdateMcpTokenRequest $request, McpToken $token, UpdateMcpTokenAction $action): RedirectResponse
    {
        $action->execute(
            $token,
            $request->user(),
            $request->string('name')->toString(),
            $request->boolean('all_projects'),
            $request->projectIds($token->team),
            $request->permissions(),
            $request->changes($token->team),
        );

        return back()->with('status', __('mcp.token_updated'));
    }

    public function destroy(Request $request, McpToken $token): RedirectResponse
    {
        Gate::authorize('administer', $token->team);
        $token->delete();

        return back()->with('status', __('mcp.token_revoked'));
    }
}
