<?php

declare(strict_types=1);

use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;

covers(RecordOccurrenceAction::class);

describe('issue numbers', function () {
    it('numbers each project\'s issues in turn, with the project\'s prefix', function () {
        $shop = Project::factory()->create(['slug' => 'acme-shop']);
        $other = Project::factory()->create();
        $record = fn (Project $project, string $title) => app(RecordOccurrenceAction::class)
            ->execute($project, new IssueSignal(IssueSource::Server, $title, 'm', null, null, [], [], CarbonImmutable::now()))->issue;

        $first = $record($shop, 'A');
        $record($other, 'A');
        $second = $record($shop, 'B');
        $again = $record($shop, 'A');

        expect($first->number)->toBe(1)
            ->and($second->number)->toBe(2)
            ->and($again->is($first))->toBeTrue()
            ->and($second->reference())->toBe('ACMESHOP-2');
    });

    it('finds an issue by its number in a search', function () {
        $project = Project::factory()->create(['slug' => 'demo']);
        Issue::factory()->for($project)->count(3)->create();

        expect(Issue::query()->where('project_id', $project->id)->whereSearch('DEMO-2')->sole()->number)->toBe(2)
            ->and(Issue::query()->where('project_id', $project->id)->whereSearch('#3')->sole()->number)->toBe(3);
    });
});
