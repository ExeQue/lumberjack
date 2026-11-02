<?php

declare(strict_types=1);

use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Performance\Queries\QueryStatisticsQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;

covers(QueryStatisticsQuery::class);

describe('where a query comes from', function () {
    it('names the route that ran it most, through the shared trace id', function () {
        $project = Project::factory()->create();
        RequestRun::factory()->for($project)->create(['trace_id' => 'a', 'method' => 'GET', 'route' => '/gallery']);
        RequestRun::factory()->for($project)->create(['trace_id' => 'b', 'method' => 'GET', 'route' => '/gallery']);
        RequestRun::factory()->for($project)->create(['trace_id' => 'c', 'method' => 'POST', 'route' => '/upload']);
        foreach (['a', 'b', 'c'] as $trace) {
            QueryRun::factory()->for($project)->create(['trace_id' => $trace]);
        }

        $rows = app(QueryStatisticsQuery::class)->get($project->id, Period::Day)['rows'];

        expect($rows[0]['most_from'])->toBe('GET /gallery')
            ->and($rows[0]['calls'])->toBe(3);
    });
});

describe('the line in the app and N+1s', function () {
    it('names the line that ran a query most often, and flags it as an N+1 when one trace ran it many times', function () {
        $project = Project::factory()->create();
        QueryRun::factory()->for($project)->count(QueryStatisticsQuery::N_PLUS_ONE_THRESHOLD)->create(['trace_id' => 'trace-a', 'location' => 'app/Http/Controllers/GalleryController.php:31']);
        QueryRun::factory()->for($project)->create(['trace_id' => 'trace-b', 'location' => 'app/Models/Photo.php:12']);

        $row = app(QueryStatisticsQuery::class)->get($project->id, Period::Day)['rows'][0];

        expect($row['location'])->toBe('app/Http/Controllers/GalleryController.php:31')
            ->and($row['per_trace'])->toBe(QueryStatisticsQuery::N_PLUS_ONE_THRESHOLD)
            ->and($row['n_plus_one'])->toBeTrue();
    });

    it('does not call a query that runs once per request an N+1', function () {
        $project = Project::factory()->create();
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $trace) {
            QueryRun::factory()->for($project)->create(['trace_id' => "trace-{$trace}"]);
        }

        $row = app(QueryStatisticsQuery::class)->get($project->id, Period::Day)['rows'][0];

        expect($row['per_trace'])->toBe(1)->and($row['n_plus_one'])->toBeFalse()->and($row['location'])->toBeNull();
    });
});
