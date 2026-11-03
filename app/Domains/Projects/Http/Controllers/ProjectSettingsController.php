<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Presenters\NotificationChannelPresenter;
use App\Domains\Issues\Models\Label;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Mcp\Triage\ClaudeTriageWorkflow;
use App\Domains\Projects\Actions\RotatePublicKeyAction;
use App\Domains\Projects\Actions\RotateServerTokenAction;
use App\Domains\Projects\Actions\UpdateProjectAction;
use App\Domains\Projects\Data\AgentClient;
use App\Domains\Projects\Http\Requests\UpdateProjectRequest;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Projects\Queries\AgentMetricsQuery;
use App\Domains\Retention\Actions\ResolveRetentionDaysAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Shared\Support\Scrubber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectSettingsController
{
    public function edit(Request $request, Project $project, ProjectPresenter $presenter, ResolveRetentionDaysAction $retention, AgentMetricsQuery $agentMetrics): Response
    {
        Gate::authorize('update', $project);

        $policies = $retention->policiesFor($project);
        $channelCounts = DB::table('log_entries')->where('project_id', $project->id)->where('occurred_at', '>=', now()->subDay())
            ->selectRaw('log_channel_id, COUNT(*) AS c')->groupBy('log_channel_id')->pluck('c', 'log_channel_id');
        $repository = $project->repository;
        $agents = $project->agents()->orderBy('host')->get();
        $metrics = $agentMetrics->get($agents);

        return Inertia::render('projects/settings', [
            'project' => $presenter->header($project, $request->user()),
            'settings' => [
                'name' => $project->name,
                'slug' => $project->slug,
                'issue_prefix' => $project->issuePrefix(),
                'allowed_origins' => $project->allowed_origins ?? [],
                'scrub_keys' => $project->scrub_keys ?? [],
                'default_scrub_keys' => Scrubber::SENSITIVE,
                'token_last_four' => $project->token_last_four,
                'public_key' => $project->public_key,
                'ingest_url' => url('/api/ingest'),
                'browser_sdk_url' => url('/sdk/v1.js'),
                'server_url' => url('/'),
            ],
            'labels' => $project->labels()->withCount('issues')->get()->map(fn (Label $l): array => [...$l->toSummary(), 'issues' => (int) $l->issues_count])->all(),
            'agents' => $agents->map(fn (ProjectAgent $a): array => [
                'id' => $a->id, 'host' => $a->host, 'name' => $a->name !== '' ? $a->name : null, 'client' => AgentClient::fromAgent($a)->label(), 'client_parts' => AgentClient::fromAgent($a)->parts(), 'last_seen_at' => $a->last_seen_at->toIso8601String(), 'online' => $a->isOnline(),
                'metrics' => $metrics[$a->id] ?? null,
            ])->all(),
            'channels' => $project->logChannels()->orderByRaw('name = ? DESC', [LogChannel::DEFAULT])->orderBy('name')->get()->map(fn (LogChannel $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'is_default' => $c->isDefault(),
                'lines_24h' => (int) ($channelCounts[$c->id] ?? 0),
                'retention_days' => $policies->first(fn (RetentionPolicy $p): bool => $p->log_channel_id === $c->id)?->days,
            ])->all(),
            'unknown_channels' => DB::table('log_entries')->where('project_id', $project->id)->where('occurred_at', '>=', now()->subDays(7))
                ->whereNotNull('sent_channel')->distinct()->limit(10)->pluck('sent_channel'),
            'retention' => collect(RetentionCategory::cases())->map(fn (RetentionCategory $c): array => [
                'category' => $c->value,
                'label' => $c->label(),
                'team_days' => $policies->first(fn (RetentionPolicy $p): bool => $p->category === $c && $p->project_id === null)->days ?? $c->defaultDays(),
                'project_days' => $policies->first(fn (RetentionPolicy $p): bool => $p->category === $c && $p->project_id === $project->id && $p->job_class === null && $p->log_channel_id === null)?->days,
            ])->all(),
            'job_overrides' => $policies->filter(fn (RetentionPolicy $p): bool => $p->project_id === $project->id && $p->job_class !== null)
                ->map(fn (RetentionPolicy $p): array => ['job_class' => $p->job_class, 'days' => $p->days])->values()->all(),
            'alerts' => [
                // The project's own channels; its rules can also use the team's shared ones.
                'channels' => NotificationChannel::query()->whereForProject($project)->orderBy('name')->get()->map(fn (NotificationChannel $c): array => app(NotificationChannelPresenter::class)->present($c))->all(),
                'available' => NotificationChannel::query()->whereAvailableTo($project)->orderBy('project_id')->orderBy('name')->get()->map(fn (NotificationChannel $c): array => app(NotificationChannelPresenter::class)->present($c))->all(),
                'types' => array_map(fn (ChannelType $t): string => $t->value, ChannelType::cases()),
                'rules' => $project->alertRules()->with('channels:id')->orderBy('id')->get()->map(fn (AlertRule $r): array => [
                    'id' => $r->id,
                    'type' => $r->type->value,
                    'label' => $r->type->label(),
                    'description' => $r->type->description(),
                    'enabled' => $r->enabled,
                    'channels' => $r->channels->modelKeys(),
                    'environments' => $r->environments ?? [],
                    'settings' => $r->type === AlertRuleType::QueueBacklog ? $r->queueBacklogSettings()->toArray() : null,
                ])->all(),
            ],
            // One entry per agent provider; Claude is the first.
            'triage' => [app(ClaudeTriageWorkflow::class)->describe($project, url('/'))],
            'repository' => $repository === null ? null : [
                'provider' => $repository->provider->value,
                'repository' => $repository->repository,
                'token_expires_at' => $repository->token_expires_at?->toDateString(),
                'labels' => $repository->labels ?? [],
                'allow_public' => $repository->allow_public,
            ],
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProjectAction $action): RedirectResponse
    {
        $action->execute(
            $project,
            $request->string('name')->toString(),
            $request->string('slug')->toString(),
            array_values((array) $request->input('allowed_origins', [])),
            array_values((array) $request->input('scrub_keys', $project->scrub_keys ?? [])),
            $request->has('issue_prefix') ? $request->string('issue_prefix')->toString() : null,
        );

        return back()->with('status', __('projects.saved'));
    }

    public function rotateToken(Request $request, Project $project, RotateServerTokenAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        return back()->with('secret', ['label' => __('projects.server_token'), 'value' => $action->execute($project)]);
    }

    public function rotatePublicKey(Request $request, Project $project, RotatePublicKeyAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);
        $action->execute($project);

        return back()->with('status', __('projects.public_key_rotated'));
    }
}
