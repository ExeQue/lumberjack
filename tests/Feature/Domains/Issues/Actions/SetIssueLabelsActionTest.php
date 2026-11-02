<?php

declare(strict_types=1);

use App\Domains\Issues\Actions\SetIssueLabelsAction;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Label;

covers(SetIssueLabelsAction::class);

describe('labelling an issue', function () {
    it('reuses the project\'s labels by name, creates the missing ones in new colors, and drops the rest', function () {
        $issue = Issue::factory()->create();
        $existing = Label::factory()->for($issue->project)->create(['name' => 'payments']);
        $issue->labels()->attach(Label::factory()->for($issue->project)->create(['name' => 'stale']));

        app(SetIssueLabelsAction::class)->execute($issue, ['Payments', ' p1 ', 'p1', '', 'flaky']);

        $labels = $issue->labels()->get();
        expect($labels->pluck('name')->all())->toBe(['flaky', 'p1', 'payments'])
            ->and($labels->firstWhere('name', 'payments')?->is($existing))->toBeTrue()
            ->and($labels->pluck('color')->unique())->toHaveCount(3)
            ->and(Label::query()->where('project_id', $issue->project_id)->count())->toBe(4);
    });

    it('keeps labels per project', function () {
        $issue = Issue::factory()->create();
        Label::factory()->create(['name' => 'payments']);

        app(SetIssueLabelsAction::class)->execute($issue, ['payments']);

        expect($issue->labels()->sole()->project_id)->toBe($issue->project_id);
    });
});
