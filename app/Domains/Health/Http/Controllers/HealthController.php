<?php

declare(strict_types=1);

namespace App\Domains\Health\Http\Controllers;

use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Actions\ArchiveHealthCheckAction;
use App\Domains\Health\Actions\DeleteHealthCheckAction;
use App\Domains\Health\Actions\SaveHealthCheckAction;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Http\Requests\ArchiveHealthCheckRequest;
use App\Domains\Health\Http\Requests\SaveHealthCheckRequest;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckResult;
use App\Domains\Health\Models\HealthOutage;
use App\Domains\Health\Queries\HealthChecksQuery;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use App\Domains\Shared\Support\TimeSeries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class HealthController
{
    use ReadsDashboardFilters;

    public const int RECENT_RESULTS = 50;

    public const int RECENT_OUTAGES = 20;

    public function index(Request $request, Project $project, ProjectPresenter $presenter, HealthChecksQuery $query): Response
    {
        Gate::authorize('view', $project);

        $period = $this->period($request);
        $selected = is_string($request->query('check')) ? $project->healthChecks()->where('uuid', $request->query('check'))->first() : null;

        return Inertia::render('health/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [...$this->filters($request), 'check' => $selected?->uuid],
            'checks' => fn (): array => $query->rows($project, $period, $this->environment($request)),
            'detail' => fn (): ?array => $selected === null ? null : [
                'uuid' => $selected->uuid,
                'ping_url' => $request->user()?->can('update', $selected) ? HealthChecksQuery::pingUrl($selected) : null,
                'results' => $selected->results()->latest('checked_at')->limit(self::RECENT_RESULTS)->get()->map(fn (HealthCheckResult $r): array => [
                    'id' => $r->id,
                    'host' => $r->host !== '' ? $r->host : null,
                    'status' => $r->status->value,
                    'message' => $r->message,
                    'duration_us' => $r->duration_us,
                    'metrics' => $r->metrics ?? [],
                    'checked_at' => $r->checked_at->toIso8601String(),
                ])->all(),
                'outages' => $this->outages($selected),
                'latency_series' => TimeSeries::count(DB::table('health_check_results')->where('health_check_id', $selected->id), $period, 'checked_at', 'AVG(duration_us)'),
            ],
            'types' => array_map(fn (HealthCheckType $t): string => $t->value, HealthCheckType::cases()),
            'intervals' => HealthCheck::INTERVALS,
            // For the add and edit dialog, which only those who may change checks open.
            'data_sources' => fn (): array => ! $request->user()?->can('update', $project) ? [] : DataSource::query()->where('project_id', $project->id)->whereNumeric()->with('dataSourceConnection:id,url,kind,environment,name')->orderBy('name')
                ->get(['uuid', 'name', 'group_path', 'format', 'environment', 'data_source_connection_id'])
                ->map(fn (DataSource $s): array => [
                    'uuid' => $s->uuid, 'name' => $s->name, 'group' => $s->pickerGroup(), 'host' => $s->pickerHost(), 'format' => $s->format->value,
                    'environment' => $s->environment !== '' && $s->dataSourceConnection?->isFor(ConnectionKind::App) !== false ? $s->environment : null,
                    'periods' => $s->dataSourceConnection !== null ? array_map(fn (Period $p): string => $p->value, $s->dataSourceConnection->kind->periods()) : null,
                ])->all(),
        ]);
    }

    /**
     * The check's latest outages, each marked when a maintenance window covering the check
     * excused it: listed still, but left out of uptime up to the window's expected end.
     *
     * @return list<array<string, mixed>>
     */
    private function outages(HealthCheck $check): array
    {
        $outages = $check->outages()->latest('started_at')->limit(self::RECENT_OUTAGES)->get();
        $windows = $outages->isEmpty() ? collect() : HealthChecksQuery::windowsOf($check, $check->project->maintenanceWindows()->whereOverlaps($outages->min('started_at'))->get());

        return array_values($outages->map(fn (HealthOutage $o): array => [
            'id' => $o->id,
            'status' => $o->status->value,
            'first_message' => $o->first_message,
            'started_at' => $o->started_at->toIso8601String(),
            'ended_at' => $o->ended_at?->toIso8601String(),
            'duration_s' => (int) $o->started_at->diffInSeconds($o->ended_at ?? now()),
            'maintenance' => $windows->contains(fn (MaintenanceWindow $w): bool => $w->starts_at->lessThanOrEqualTo($o->ended_at ?? now()) && $w->excusedUntil()->greaterThanOrEqualTo($o->started_at)),
        ])->all());
    }

    public function store(SaveHealthCheckRequest $request, Project $project, SaveHealthCheckAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $check = $action->execute($project, null, $request->validated(), $request->config());

        return redirect()->route('health.index', ['project' => $project, 'check' => $check->uuid])->with('status', __('health.saved', ['check' => $check->displayName()]));
    }

    public function update(SaveHealthCheckRequest $request, Project $project, HealthCheck $healthCheck, SaveHealthCheckAction $action): RedirectResponse
    {
        Gate::authorize('update', $healthCheck);

        $action->execute($project, $healthCheck, $request->validated(), $request->config());

        return back()->with('status', __('health.saved', ['check' => $healthCheck->displayName()]));
    }

    public function archive(ArchiveHealthCheckRequest $request, Project $project, HealthCheck $healthCheck, ArchiveHealthCheckAction $action): RedirectResponse
    {
        Gate::authorize('update', $healthCheck);

        $action->execute($healthCheck, $request->boolean('archived'));

        return back();
    }

    public function destroy(Project $project, HealthCheck $healthCheck, DeleteHealthCheckAction $action): RedirectResponse
    {
        Gate::authorize('update', $healthCheck);

        $action->execute($healthCheck);

        return redirect()->route('health.index', $project)->with('status', __('health.deleted', ['check' => $healthCheck->displayName()]));
    }
}
