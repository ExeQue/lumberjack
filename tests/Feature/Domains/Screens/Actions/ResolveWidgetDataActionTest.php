<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Screens\Actions\ResolveWidgetDataAction;
use App\Domains\Screens\Data\WidgetScope;
use App\Domains\Screens\Enums\ReleaseScope;
use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Screens\Exceptions\UnknownWidgetSourceException;
use App\Domains\Screens\Support\WidgetSources;
use Tests\Support\Performance\CreatesRequestCounts;

covers(ResolveWidgetDataAction::class, WidgetSources::class);

uses(CreatesRequestCounts::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0, 30));
    $this->project = Project::factory()->create();
    $this->resolve = fn (WidgetType $type, string $source, array $options = [], ?WidgetScope $scope = null): array => app(ResolveWidgetDataAction::class)
        ->execute($this->project->team, $type, $source, $scope ?? new WidgetScope(), $options);
});

describe('the registry', function () {
    it('resolves every source it lists, even without data', function (WidgetType $type, string $source) {
        $data = ($this->resolve)($type, $source);

        expect($data)->toMatchArray(['type' => $type->value, 'source' => $source])
            ->and(json_encode($data))->toBeString()
            ->and(__(WidgetSources::find($type, $source)->label()))->not->toStartWith('screens.');
    })->with(fn (): array => collect(WidgetSources::all())->flatMap(fn (array $sources, string $type): array => collect($sources)->mapWithKeys(fn ($s, string $id): array => ["{$type} {$id}" => [WidgetType::from($type), $id]])->all())->all());

    it('has sources for every data visualization', function (WidgetType $type) {
        expect(WidgetSources::for($type))->not->toBeEmpty();
    })->with(WidgetType::cases());

    it('refuses a source its type does not have', function () {
        expect(fn () => ($this->resolve)(WidgetType::Gauge, 'latest_issues'))->toThrow(UnknownWidgetSourceException::class);
    });
});

describe('a stat', function () {
    it('shows the period with its trend, state and sparkline', function () {
        $this->createRequestCount($this->project, 30, minute: now()->subHour()->startOfMinute());
        $this->createRequestCount($this->project, 20, minute: now()->subDay()->subHour()->startOfMinute());

        $data = ($this->resolve)(WidgetType::Stat, 'requests');

        expect($data)->toMatchArray(['unit' => 'count', 'value' => 30.0, 'previous' => 20.0, 'change' => 0.5, 'state' => null])
            ->and(collect($data['sparkline'])->sum('v'))->toEqual(30);
    });

    it('colors a value past its limits, overridable per widget', function () {
        $this->createRequestCount($this->project, 97);
        $this->createRequestCount($this->project, 3, 500);

        expect(($this->resolve)(WidgetType::Stat, 'error_rate')['state'])->toBe('amber')
            ->and(($this->resolve)(WidgetType::Stat, 'error_rate', ['red' => 0.02])['state'])->toBe('red')
            ->and(($this->resolve)(WidgetType::Stat, 'error_rate', ['amber' => 0.1, 'red' => 0.2])['state'])->toBe('ok');
    });

    it('turns red below the limit where low is bad', function () {
        ProjectAgent::factory()->for($this->project)->offline()->create();

        expect(($this->resolve)(WidgetType::Stat, 'agents_online'))->toMatchArray(['value' => 0.0, 'state' => 'red']);
    });

    it('reads only the team\'s projects, and the picked ones', function () {
        $other = Project::factory()->create(['team_id' => $this->project->team_id]);
        $foreign = Project::factory()->create();
        Issue::factory()->for($this->project)->create();
        Issue::factory()->for($other)->count(2)->create();
        Issue::factory()->for($foreign)->count(5)->create();

        expect(($this->resolve)(WidgetType::Stat, 'open_issues')['value'])->toEqual(3)
            ->and(($this->resolve)(WidgetType::Stat, 'open_issues', scope: new WidgetScope(projects: [$other->uuid]))['value'])->toEqual(2)
            ->and(($this->resolve)(WidgetType::Stat, 'open_issues', scope: new WidgetScope(projects: [$foreign->uuid]))['value'])->toEqual(0);
    });

    it('covers the current release only when asked to', function () {
        RequestRun::factory()->for($this->project)->count(2)->create(['release' => 'v1', 'occurred_at' => now()->subHours(3)]);
        RequestRun::factory()->for($this->project)->count(5)->create(['release' => 'v2', 'occurred_at' => now()->subHour()]);

        expect(($this->resolve)(WidgetType::Stat, 'current_release')['value'])->toBe('v2')
            ->and(($this->resolve)(WidgetType::Stat, 'requests', scope: new WidgetScope(release: ReleaseScope::Current))['value'])->toEqual(5)
            ->and(($this->resolve)(WidgetType::Stat, 'requests', scope: new WidgetScope(release: ReleaseScope::Previous))['value'])->toEqual(2);
    });
});

describe('a gauge, a chart and a list', function () {
    it('puts a share on a scale from 0 to 1', function () {
        QueueSample::query()->create(['project_id' => $this->project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => 'mail', 'size' => 150, 'sampled_at' => now()->subMinute()]);

        expect(($this->resolve)(WidgetType::Gauge, 'queue_size'))->toMatchArray(['value' => 150.0, 'min' => 0.0, 'max' => 1500.0, 'state' => 'amber'])
            ->and(($this->resolve)(WidgetType::Gauge, 'error_rate'))->toMatchArray(['value' => null, 'max' => 1.0]);
    });

    it('charts requests and server errors side by side', function () {
        $this->createRequestCount($this->project, 9);
        $this->createRequestCount($this->project, 1, 503);

        $series = collect(($this->resolve)(WidgetType::Chart, 'requests_errors')['series'])->keyBy('name');

        expect($series->keys()->all())->toBe(['requests', 'errors'])
            ->and(collect($series['requests']['points'])->sum('v'))->toEqual(10)
            ->and(collect($series['errors']['points'])->sum('v'))->toEqual(1);
    });

    it('splits a chart by project', function () {
        $other = Project::factory()->create(['team_id' => $this->project->team_id, 'name' => 'Zeta']);
        $this->createRequestCount($other, 4);

        $series = ($this->resolve)(WidgetType::Chart, 'requests', ['split_by' => 'project']);

        expect($series['series'][0]['name'])->toBe('Zeta')->and($series['series'])->toHaveCount(2);
    });

    it('groups failed jobs by job class, filtered by the scope', function () {
        JobRun::factory()->for($this->project)->failed()->count(3)->create(['name' => 'App\\Jobs\\Bill', 'created_at' => now()->subHour()]);
        JobRun::factory()->for($this->project)->failed()->create(['name' => 'App\\Jobs\\Mail', 'created_at' => now()->subHour()]);

        $rows = ($this->resolve)(WidgetType::List, 'failed_jobs')['rows'];
        $filtered = ($this->resolve)(WidgetType::List, 'failed_jobs', scope: new WidgetScope(filter: '*Mail'))['rows'];

        expect(array_column($rows, 'failures', 'name'))->toBe(['App\\Jobs\\Bill' => 3, 'App\\Jobs\\Mail' => 1])
            ->and(array_column($filtered, 'name'))->toBe(['App\\Jobs\\Mail']);
    });

    it('leaves issue titles and host names out of a shared screen', function () {
        Issue::factory()->for($this->project)->create(['title' => 'Private']);
        ProjectAgent::factory()->for($this->project)->create(['host' => 'db.internal']);
        $resolve = fn (string $source): array => app(ResolveWidgetDataAction::class)->execute($this->project->team, WidgetType::List, $source, new WidgetScope(), aggregatesOnly: true);

        expect($resolve('latest_issues')['rows'][0]['title'])->toBeNull()
            ->and($resolve('agents')['rows'][0]['host'])->toBe('host 1');
    });
});
