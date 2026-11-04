<?php

declare(strict_types=1);

use App\Domains\Issues\Http\Controllers\IssueController;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueEvent;
use App\Domains\Issues\Models\IssueNote;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Shared\ReadsQueryCounts;
use Tests\Support\Teams\CreatesTeamMembers;

covers(IssueController::class);

uses(CreatesTeamMembers::class, ReadsQueryCounts::class);

describe('one issue', function () {
    it('charts its occurrences over the chosen period, the last 24 hours by default', function (?string $period, string $shown, int $buckets) {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $issue = Issue::factory()->for($project)->create();

        $this->actingAs($user)->get(route('issues.show', [$project, $issue, 'period' => $period]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('issues/show')
                ->where('period', $shown)
                ->has('series', fn ($series) => $series->etc())
                ->where('series', fn ($series) => count($series) >= $buckets && count($series) <= $buckets + 1));
    })->with([
        'default' => [null, '24h', 24],
        'a week' => ['7d', '7d', 28],
        'unknown' => ['1y', '24h', 24],
    ]);
});

describe('the queries of the issue pages', function () {
    it('lists any number of issues in the same few queries', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $page = fn () => $this->actingAs($user)->get(route('issues.index', $project))->assertOk();
        Issue::factory()->for($project)->create();
        $one = $this->queriesRunBy($page);

        Issue::factory()->for($project)->count(3)->create();

        expect($this->queriesRunBy($page))->toBe($one);
    });

    it('reads the notes once for the notes and the history, with the people who wrote them and the events', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $issue = Issue::factory()->for($project)->create();
        $page = fn () => $this->actingAs($user)->get(route('issues.show', [$project, $issue]))->assertOk();
        IssueNote::factory()->for($issue)->for($user)->create(['body' => 'First look', 'created_at' => now()->subHour()]);
        IssueEvent::factory()->for($issue)->for($user)->create();
        $one = $this->queriesRunBy($page);

        foreach (User::factory()->inTeam($project->team)->count(3)->create() as $author) {
            IssueNote::factory()->for($issue)->for($author)->create();
            IssueEvent::factory()->for($issue)->for($author)->create();
        }

        expect($this->queriesRunBy($page))->toBe($one);
        $page()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('notes', 4)
            ->where('notes.3.author', $user->name)
            ->where('history', fn ($history) => collect($history)->where('type', 'note')->count() === 4
                && collect($history)->every(fn (array $entry): bool => $entry['actor'] !== null)));
    });
});
