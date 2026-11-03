<?php

declare(strict_types=1);

namespace App\Domains\Logs\Http\Controllers;

use App\Domains\Logs\Enums\LogLevel;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Logs\Models\LogEntry;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LogController
{
    use ReadsDashboardFilters;

    public const int LIMIT = 200;

    public const int WEEK_CACHE_SECONDS = 300;

    public function __invoke(Request $request, Project $project, ProjectPresenter $presenter): Response
    {
        Gate::authorize('view', $project);

        $period = $this->period($request, Period::Hour);
        $environment = $this->environment($request);
        $channel = (string) $request->query('channel', '');
        $level = LogLevel::fromName((string) $request->query('level', 'debug'));
        $search = trim((string) $request->query('search', ''));
        $release = (string) $request->query('release', '');
        $channels = $project->logChannels()->orderByRaw('name = ? DESC', [LogChannel::DEFAULT])->orderBy('name')->get();
        $channelId = $channels->firstWhere('name', $channel)?->id;
        $since = $period->since();

        return Inertia::render('logs/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [...$this->filters($request, Period::Hour), 'channel' => $channel, 'level' => $level->key(), 'search' => $search, 'release' => $release],
            // A week of lines is a lot to group on every visit: these two are reused for five minutes.
            'releases' => fn (): array => Cache::remember('logs:'.$project->id.':releases', self::WEEK_CACHE_SECONDS, fn (): array => DB::table('log_entries')
                ->where('project_id', $project->id)->where('occurred_at', '>=', now()->subDays(7))
                ->whereNotNull('release')->distinct()->orderByDesc('release')->limit(20)->pluck('release')->all()),
            'channels' => function () use ($project, $since, $channels): array {
                $counts = DB::table('log_entries')->where('project_id', $project->id)->where('occurred_at', '>=', $since)
                    ->selectRaw('log_channel_id, COUNT(*) AS c')->groupBy('log_channel_id')->pluck('c', 'log_channel_id');

                return $channels->map(fn (LogChannel $c): array => ['name' => $c->name, 'count' => (int) ($counts[$c->id] ?? 0)])->all();
            },
            'unknown_channels' => fn (): array => collect(Cache::remember('logs:'.$project->id.':sent-channels', self::WEEK_CACHE_SECONDS, fn (): array => DB::table('log_entries')
                ->where('project_id', $project->id)->where('occurred_at', '>=', now()->subDays(7))
                ->whereNotNull('sent_channel')->selectRaw('sent_channel, COUNT(*) AS c')->groupBy('sent_channel')->orderByDesc('c')->limit(5)->get()
                ->map(fn (object $row): array => ['name' => (string) $row->sent_channel, 'count' => (int) $row->c])->all()))
                ->reject(fn (array $row): bool => $channels->contains('name', $row['name']))->values()->all(),
            'lines' => fn (): array => LogEntry::query()
                ->where('project_id', $project->id)
                ->where('occurred_at', '>=', $since)
                ->whereLevelAtLeast($level)
                ->when($environment !== null)->where('environment', $environment)
                ->when($channel !== '')->where('log_channel_id', $channelId ?? 0)
                ->when($search !== '')->whereMessageMatches($search)
                ->when($release !== '')->where('release', $release)
                ->with('channel')
                ->latest('occurred_at')
                ->latest('id')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (LogEntry $l): array => [
                    'id' => $l->id,
                    'at' => $l->occurred_at->format('Y-m-d\TH:i:s.vP'),
                    'level' => $l->level->key(),
                    'channel' => $l->channel?->name,
                    'sent_channel' => $l->sent_channel,
                    'message' => $l->message,
                    'context' => $l->context,
                    'trace_id' => $l->trace_id,
                    'environment' => $l->environment,
                ])->all(),
            'limit' => self::LIMIT,
        ]);
    }
}
