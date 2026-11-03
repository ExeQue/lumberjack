<?php

declare(strict_types=1);

use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueEvent;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Releases\Queries\ReleaseHealthQuery;
use App\Domains\Shared\Enums\Period;
use Carbon\CarbonImmutable;

covers(ReleaseHealthQuery::class);

describe('the release health', function () {
    it('lists releases newest first with their requests, 5xx rate and p95', function () {
        $project = Project::factory()->create();
        RequestRun::factory()->for($project)->count(4)->create(['release' => 'v1', 'duration_us' => 100_000, 'occurred_at' => now()->subHours(5)]);
        RequestRun::factory()->for($project)->count(3)->create(['release' => 'v2', 'duration_us' => 100_000, 'occurred_at' => now()->subHours(2)]);
        RequestRun::factory()->for($project)->create(['release' => 'v2', 'duration_us' => 400_000, 'status_code' => 500, 'occurred_at' => now()->subHour()]);
        RequestRun::factory()->for($project)->create(['release' => null, 'occurred_at' => now()->subHour()]);

        $releases = app(ReleaseHealthQuery::class)->get($project->id, Period::Day);

        expect(array_column($releases, 'release'))->toBe(['v2', 'v1'])
            ->and($releases[0])->toMatchArray(['requests' => 4, 'error_rate' => 0.25, 'p95' => 400_000, 'occurrences' => 0])
            ->and($releases[1])->toMatchArray(['requests' => 4, 'error_rate' => 0.0, 'p95' => 100_000, 'previous' => null]);
    });

    it('compares each release with the one before it', function () {
        $project = Project::factory()->create();
        RequestRun::factory()->for($project)->count(2)->create(['release' => 'v1', 'duration_us' => 100_000, 'occurred_at' => now()->subHours(5)]);
        RequestRun::factory()->for($project)->create(['release' => 'v1', 'duration_us' => 100_000, 'status_code' => 500, 'occurred_at' => now()->subHours(5)]);
        RequestRun::factory()->for($project)->create(['release' => 'v1', 'duration_us' => 100_000, 'status_code' => 500, 'occurred_at' => now()->subHours(5)]);
        RequestRun::factory()->for($project)->count(3)->create(['release' => 'v2', 'duration_us' => 150_000, 'occurred_at' => now()->subHours(2)]);
        RequestRun::factory()->for($project)->create(['release' => 'v2', 'duration_us' => 150_000, 'status_code' => 500, 'occurred_at' => now()->subHours(2)]);

        [$v2] = app(ReleaseHealthQuery::class)->get($project->id, Period::Day);

        // 5xx from 50 % to 25 %, p95 from 100 ms to 150 ms.
        expect($v2['previous'])->toBe(['release' => 'v1', 'error_rate' => 0.5, 'p95' => 100_000])
            ->and($v2['change'])->toBe(['error_rate' => -0.5, 'p95' => 0.5]);
    });

    it('counts an issue as new in the release of its earliest occurrence', function () {
        $project = Project::factory()->create();
        // The issue keeps the release of its first occurrence from when it is created.
        $issue = Issue::factory()->for($project)->create(['first_seen_at' => now()->subHours(3), 'last_release' => 'v2', 'first_release' => 'v1', 'first_environment' => 'production']);
        Occurrence::factory()->for($issue)->create(['release' => 'v1', 'occurred_at' => now()->subHours(3)]);
        Occurrence::factory()->for($issue)->create(['release' => 'v2', 'occurred_at' => now()->subHour()]);
        $old = Issue::factory()->for($project)->create(['first_seen_at' => now()->subDays(3)]);
        Occurrence::factory()->for($old)->create(['release' => 'v2', 'occurred_at' => now()->subHour()]);

        $query = app(ReleaseHealthQuery::class);
        $releases = collect($query->get($project->id, Period::Day))->keyBy('release');

        expect($releases['v1'])->toMatchArray(['new_issues' => 1, 'occurrences' => 1])
            ->and($releases['v2'])->toMatchArray(['new_issues' => 0, 'occurrences' => 2])
            ->and($query->newIssueIds($project->id, Period::Day, null, 'v1'))->toBe([$issue->id]);
    });

    it('counts an issue as new in the release of its earliest occurrence in the chosen environment', function () {
        $project = Project::factory()->create();
        $issue = Issue::factory()->for($project)->create(['first_seen_at' => now()->subHours(3), 'first_release' => 'v1', 'first_environment' => 'staging']);
        Occurrence::factory()->for($issue)->create(['environment' => 'staging', 'release' => 'v1', 'occurred_at' => now()->subHours(3)]);
        Occurrence::factory()->for($issue)->create(['environment' => 'production', 'release' => 'v2', 'occurred_at' => now()->subHours(2)]);
        Occurrence::factory()->for($issue)->create(['environment' => 'production', 'release' => 'v3', 'occurred_at' => now()->subHour()]);

        $query = app(ReleaseHealthQuery::class);

        expect($query->newIssueIds($project->id, Period::Day, 'production', 'v2'))->toBe([$issue->id])
            ->and($query->newIssueIds($project->id, Period::Day, 'production', 'v1'))->toBe([])
            ->and($query->newIssueIds($project->id, Period::Day, null, 'v1'))->toBe([$issue->id]);
    });

    it('keeps the release and environment of an issue\'s first occurrence on the issue', function () {
        $project = Project::factory()->create();
        $signal = fn (string $release): IssueSignal => new IssueSignal(IssueSource::Server, 'RuntimeException', 'Boom', null, null, [], [], CarbonImmutable::now(), environment: 'staging', release: $release);

        app(RecordOccurrenceAction::class)->execute($project, $signal('v1'));
        $issue = app(RecordOccurrenceAction::class)->execute($project, $signal('v2'))->issue;

        expect($issue->refresh())->first_release->toBe('v1')->first_environment->toBe('staging')->last_release->toBe('v2');
    });

    it('counts the regressions a release reopened', function () {
        $project = Project::factory()->create();
        $issue = Issue::factory()->for($project)->create(['first_seen_at' => now()->subDays(3)]);
        Occurrence::factory()->for($issue)->create(['release' => 'v2', 'occurred_at' => now()->subHour()]);
        IssueEvent::query()->create(['issue_id' => $issue->id, 'type' => IssueEventType::Regressed, 'data' => ['release' => 'v2', 'environment' => 'production'], 'occurred_at' => now()->subHour()]);

        expect(app(ReleaseHealthQuery::class)->get($project->id, Period::Day)[0])->toMatchArray(['release' => 'v2', 'regressions' => 1])
            ->and(app(ReleaseHealthQuery::class)->get($project->id, Period::Day, 'staging'))->toBe([]);
    });
});
