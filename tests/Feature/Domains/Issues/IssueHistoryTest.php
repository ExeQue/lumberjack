<?php

declare(strict_types=1);

use App\Domains\Issues\Actions\AssignIssueAction;
use App\Domains\Issues\Actions\ChangeIssueStatusAction;
use App\Domains\Issues\Actions\RecordIssueEventAction;
use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Actions\SetIssueLabelsAction;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Presenters\IssuePresenter;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;

covers(RecordIssueEventAction::class, IssuePresenter::class);

describe('the issue history', function () {
    it('tells what happened, by whom, newest first, with the notes in between', function () {
        $project = Project::factory()->create();
        $morten = User::factory()->inTeam($project->team)->create(['name' => 'Morten']);
        $bot = McpToken::factory()->for($project->team)->create(['name' => 'triage-bot']);
        $signal = fn (): IssueSignal => new IssueSignal(IssueSource::Server, 'RuntimeException', 'Card declined', null, null, [], [], CarbonImmutable::now(), environment: 'production');

        $this->travelTo(now()->subMinutes(10));
        $issue = app(RecordOccurrenceAction::class)->execute($project, $signal())->issue;
        $this->travelBack();
        $this->travelTo(now()->subMinutes(8));
        app(SetIssueLabelsAction::class)->execute($issue, ['payments'], token: $bot);
        $this->travelBack();
        $this->travelTo(now()->subMinutes(6));
        app(AssignIssueAction::class)->execute($issue, $morten, $morten);
        $this->travelBack();
        $this->travelTo(now()->subMinutes(4));
        app(ChangeIssueStatusAction::class)->execute($issue, IssueStatus::Resolved, $morten, 'Fixed in #482.');
        $this->travelBack();
        $this->travelTo(now()->subMinutes(2));
        app(RecordOccurrenceAction::class)->execute($project, $signal());
        $this->travelBack();

        $history = app(IssuePresenter::class)->history($issue->refresh());

        expect(array_column($history, 'type'))->toBe(['regressed', 'status_changed', 'note', 'assigned', 'labels_changed', 'created'])
            ->and($history[0]['data'])->toEqual(['release' => null, 'environment' => 'production'])
            ->and($history[1]['data'])->toEqual(['from' => 'open', 'to' => 'resolved'])
            ->and($history[2])->toMatchArray(['actor' => 'Morten', 'data' => ['body' => 'Fixed in #482.', 'status' => 'resolved']])
            ->and($history[3]['data'])->toEqual(['from' => null, 'to' => 'Morten'])
            ->and($history[4])->toMatchArray(['actor' => 'triage-bot', 'via_mcp' => true, 'data' => ['added' => ['payments'], 'removed' => []]]);
    });

    it('records nothing for a change that changes nothing', function () {
        $project = Project::factory()->create();
        $issue = app(RecordOccurrenceAction::class)->execute($project, new IssueSignal(IssueSource::Server, 'E', 'm', null, null, [], [], CarbonImmutable::now()))->issue;

        app(AssignIssueAction::class)->execute($issue, null);
        app(SetIssueLabelsAction::class)->execute($issue, []);

        expect($issue->events()->pluck('type')->map->value->all())->toBe(['created']);
    });
});
