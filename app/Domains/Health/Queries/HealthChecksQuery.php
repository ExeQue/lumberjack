<?php

declare(strict_types=1);

namespace App\Domains\Health\Queries;

use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckHost;
use App\Domains\Health\Models\HealthOutage;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * A project's checks as the Health page, the overview and MCP show them: their live
 * status, uptime over a period and a status strip, both from the outages.
 */
class HealthChecksQuery
{
    /**
     * @return EloquentCollection<int, HealthCheck>
     */
    public function checks(Project $project, ?string $environment = null, bool $archived = true): EloquentCollection
    {
        return HealthCheck::query()
            ->where('project_id', $project->id)
            // A check Lumberjack runs without an environment label shows under every environment.
            ->when($environment !== null)->whereIn('environment', [$environment ?? '', ''])
            ->when(! $archived)->whereNotArchived()
            ->with('hosts')
            ->orderBy('source')->orderByRaw('COALESCE(name, slug)')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(Project $project, Period $period, ?string $environment = null): array
    {
        $checks = $this->checks($project, $environment);
        $outages = HealthOutage::query()
            ->whereIn('health_check_id', $checks->modelKeys())
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $period->since()))
            ->get()
            ->groupBy('health_check_id');

        return array_values($checks->map(fn (HealthCheck $check): array => $this->row($check, $period, $outages->get($check->id, collect())))->all());
    }

    /**
     * How many checks stand where, archived ones left out.
     *
     * @return array{total: int, ok: int, degraded: int, down: int, stale: int, pending: int}
     */
    public function summary(Project $project, ?string $environment = null): array
    {
        $states = $this->checks($project, $environment, archived: false)->map(fn (HealthCheck $check): string => self::state($check))->countBy();

        return [
            'total' => $states->sum(),
            'ok' => (int) $states->get('ok', 0),
            'degraded' => (int) $states->get('degraded', 0),
            'down' => (int) $states->get('down', 0),
            'stale' => (int) $states->get('stale', 0),
            'pending' => (int) $states->get('pending', 0),
        ];
    }

    /**
     * "ok", "degraded", "down", "stale" or "pending" (nothing reported yet).
     */
    public static function state(HealthCheck $check): string
    {
        return $check->isStale() ? 'stale' : ($check->liveStatus()->value ?? 'pending');
    }

    /**
     * @param  Collection<int, HealthOutage>  $outages
     * @return array<string, mixed>
     */
    private function row(HealthCheck $check, Period $period, Collection $outages): array
    {
        $latest = $check->hosts->sortByDesc('last_result_at')->first();

        return [
            'uuid' => $check->uuid,
            'source' => $check->source->value,
            'type' => $check->type?->value,
            'name' => $check->displayName(),
            'custom_name' => $check->name,
            'slug' => $check->slug,
            'description' => $check->description,
            'environment' => $check->environment !== '' ? $check->environment : null,
            'state' => self::state($check),
            'muted' => $check->muted,
            'archived' => $check->archived_at !== null,
            'degrade_after' => $check->degrade_after,
            'recover_after' => $check->recover_after,
            'thresholds_overridden' => $check->thresholds_overridden,
            'interval_minutes' => $check->interval_minutes,
            'config' => $check->config,
            'last_result_at' => $check->last_result_at?->toIso8601String(),
            'last_status' => $latest?->last_status?->value,
            'last_message' => $latest?->last_message,
            'duration_us' => $latest?->last_duration_us,
            'metrics' => $latest->last_metrics ?? [],
            'uptime' => $check->isStale() ? null : $this->uptime($check, $period, $outages),
            'strip' => $this->strip($check, $period, $outages),
            'hosts' => $check->isReported() ? $check->hosts->map(fn (HealthCheckHost $h): array => [
                'host' => $h->host !== '' ? $h->host : null,
                'state' => $h->isStale() ? 'stale' : ($h->status->value ?? 'pending'),
                'last_status' => $h->last_status?->value,
                'last_message' => $h->last_message,
                'last_result_at' => $h->last_result_at?->toIso8601String(),
                'duration_us' => $h->last_duration_us,
                'metrics' => $h->last_metrics ?? [],
            ])->values()->all() : [],
        ];
    }

    /**
     * The share of the period the check was not down: degraded counts as up, as on a
     * status page. Counted from when the check existed; an outage of a check that went
     * quiet ends at its last result.
     *
     * @param  Collection<int, HealthOutage>  $outages
     */
    public function uptime(HealthCheck $check, Period $period, Collection $outages): ?float
    {
        $now = now()->toImmutable();
        $start = $period->since()->max($check->created_at ?? $period->since());
        $total = $start->diffInSeconds($now);

        if ($total <= 0) {
            return null;
        }

        $down = 0.0;

        foreach ($outages->where('status', HealthStatus::Down) as $outage) {
            $from = $outage->started_at->max($start);
            $to = ($outage->ended_at ?? $check->last_result_at ?? $now)->min($now);
            $down += max(0, $from->diffInSeconds($to, false));
        }

        return round(max(0, 1 - $down / $total), 5);
    }

    /**
     * One segment per period bucket, colored by the worst the check was in it; null
     * before the check existed.
     *
     * @param  Collection<int, HealthOutage>  $outages
     * @return list<array{t: string, s: string|null}>
     */
    private function strip(HealthCheck $check, Period $period, Collection $outages): array
    {
        $size = $period->bucketSeconds();
        $start = intdiv($period->since()->getTimestamp(), $size) * $size;
        $created = ($check->created_at ?? now())->getTimestamp();
        $strip = [];

        for ($i = 0; $i <= $period->buckets(); $i++) {
            $from = $start + $i * $size;

            if ($from > now()->getTimestamp()) {
                break;
            }

            $to = $from + $size;
            $worst = HealthStatus::worst($outages
                ->filter(fn (HealthOutage $o): bool => $o->started_at->getTimestamp() < $to && ($o->ended_at === null || $o->ended_at->getTimestamp() >= $from))
                ->map(fn (HealthOutage $o): HealthStatus => $o->status));

            $strip[] = ['t' => date(DATE_ATOM, $from), 's' => $to <= $created ? null : ($worst ?? HealthStatus::Ok)->value];
        }

        return $strip;
    }

    public static function pingUrl(HealthCheck $check): ?string
    {
        return $check->type === HealthCheckType::Heartbeat && $check->ping_token !== null ? route('health.ping', $check->ping_token) : null;
    }
}
