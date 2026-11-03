<?php

declare(strict_types=1);

use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;

covers(RecordOccurrenceAction::class);

it('counts each signed-in user an issue happened to once', function () {
    $project = Project::factory()->create();
    $signal = fn (?array $user): IssueSignal => new IssueSignal(
        source: IssueSource::Server,
        title: 'RuntimeException',
        message: 'Boom',
        file: 'app/Foo.php',
        line: 1,
        stacktrace: [],
        context: $user !== null ? ['user' => $user] : [],
        occurredAt: CarbonImmutable::now(),
    );

    $action = app(RecordOccurrenceAction::class);
    $action->execute($project, $signal(['id' => 7]));
    $action->execute($project, $signal(['id' => 7]));
    $action->execute($project, $signal(['id' => 'b2']));
    $occurrence = $action->execute($project, $signal(null));

    expect($occurrence->issue->refresh())
        ->occurrences_count->toBe(4)
        ->users_count->toBe(2)
        ->and($occurrence->user_id)->toBeNull();
});
