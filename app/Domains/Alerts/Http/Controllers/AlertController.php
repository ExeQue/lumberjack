<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Controllers;

use App\Domains\Alerts\Actions\CustomizeAlertRuleAction;
use App\Domains\Alerts\Actions\ResetAlertRuleAction;
use App\Domains\Alerts\Actions\SaveNotificationChannelAction;
use App\Domains\Alerts\Actions\SendTestAlertAction;
use App\Domains\Alerts\Actions\SetAlertRuleAddOnsAction;
use App\Domains\Alerts\Actions\UpdateAlertRuleAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Exceptions\AlertExceptionInterface;
use App\Domains\Alerts\Http\Requests\SaveNotificationChannelRequest;
use App\Domains\Alerts\Http\Requests\UpdateAlertRuleAddOnsRequest;
use App\Domains\Alerts\Http\Requests\UpdateAlertRuleRequest;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Presenters\AlertRulePresenter;
use App\Domains\Alerts\Presenters\NotificationChannelPresenter;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AlertController
{
    public function index(Request $request, NotificationChannelPresenter $presenter, AlertRulePresenter $rules): Response
    {
        $team = $request->user()->currentTeam;
        abort_if($team === null, 404);

        return Inertia::render('team/alerts', [
            // The team's shared channels; a project's own channels and its rules are in its settings.
            'channels' => $team->notificationChannels()->whereTeamWide()->orderBy('name')->get()->map(fn (NotificationChannel $c): array => $presenter->present($c))->all(),
            // Webhooks belong to one project, so they are added in its settings.
            'types' => collect(ChannelType::cases())->reject(fn (ChannelType $t): bool => $t === ChannelType::Webhook)->map(fn (ChannelType $t): string => $t->value)->values()->all(),
            'projects' => $team->projects()->orderBy('name')->get()->map(fn (Project $p): array => ['uuid' => $p->uuid, 'name' => $p->name])->all(),
            'rules' => $rules->forTeam($team),
            'can_administer' => $request->user()->canAdministerTeam($team),
        ]);
    }

    public function storeChannel(SaveNotificationChannelRequest $request, SaveNotificationChannelAction $action): RedirectResponse
    {
        try {
            $channel = $action->execute($request->user()->currentTeam, ChannelType::from($request->string('type')->toString()), $request->string('name')->toString(), (array) $request->input('config'), project: $request->project());
        } catch (AlertExceptionInterface $e) {
            return back()->withErrors(['channel' => $e->getMessage()]);
        }

        return $channel->revealSecret !== null
            ? back()->with('status', __('alerts.channel_saved'))->with('secret', ['label' => __('alerts.webhook_secret'), 'value' => $channel->revealSecret])
            : back()->with('status', __('alerts.channel_saved'));
    }

    public function updateChannel(SaveNotificationChannelRequest $request, NotificationChannel $channel, SaveNotificationChannelAction $action): RedirectResponse
    {
        try {
            $action->execute($channel->team, ChannelType::from($request->string('type')->toString()), $request->string('name')->toString(), (array) $request->input('config', []), $channel);
        } catch (AlertExceptionInterface $e) {
            return back()->withErrors(['channel' => $e->getMessage()]);
        }

        return back()->with('status', __('alerts.channel_saved'));
    }

    public function destroyChannel(Request $request, NotificationChannel $channel): RedirectResponse
    {
        Gate::authorize('administer', $channel->team);
        $channel->delete();

        return back();
    }

    public function testChannel(Request $request, NotificationChannel $channel, SendTestAlertAction $action): RedirectResponse
    {
        Gate::authorize('administer', $channel->team);

        $failure = $action->execute($channel);

        return $failure === null
            ? back()->with('status', __('alerts.test_sent'))
            : back()->withErrors(['channel' => $failure]);
    }

    public function updateRule(UpdateAlertRuleRequest $request, AlertRule $rule, UpdateAlertRuleAction $action): RedirectResponse
    {
        $action->execute($rule, $request->boolean('enabled'), array_values(array_map('intval', (array) $request->input('channels', []))), $request->input('environments'), $request->settings());

        return back();
    }

    /** The project gets a rule of its own for the type, starting as the team's. */
    public function customizeRule(Project $project, AlertRuleType $type, CustomizeAlertRuleAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);
        $action->execute($project, $type);

        return back()->with('status', __('alerts.rule_customized'));
    }

    /** The channels the project adds to a team rule it uses. */
    public function updateAddOns(UpdateAlertRuleAddOnsRequest $request, Project $project, AlertRule $rule, SetAlertRuleAddOnsAction $action): RedirectResponse
    {
        $action->execute($project, $rule, array_values(array_map('intval', (array) $request->input('channels', []))));

        return back();
    }

    /** The project's own rule goes, so the team's counts again. */
    public function resetRule(AlertRule $rule, ResetAlertRuleAction $action): RedirectResponse
    {
        abort_if($rule->project === null, 404);
        Gate::authorize('update', $rule->project);
        $action->execute($rule);

        return back()->with('status', __('alerts.rule_reset'));
    }
}
