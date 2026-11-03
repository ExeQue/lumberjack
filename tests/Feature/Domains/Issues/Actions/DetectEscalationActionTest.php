<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\SendIssueAlertsAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Actions\DetectEscalationAction;
use App\Domains\Issues\Events\IssueEscalated;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

covers(DetectEscalationAction::class);

beforeEach(function () {
    $this->occurrences = function (Issue $issue, int $count, Closure $at): void {
        foreach (range(1, $count) as $i) {
            Occurrence::factory()->for($issue)->create(['project_id' => $issue->project_id, 'occurred_at' => $at($i)]);
        }
    };
});

describe('escalation', function () {
    beforeEach(fn () => Event::fake([IssueEscalated::class]));

    it('flags an issue that suddenly happens far more often than usual, and resurfaces it', function () {
        $issue = Issue::factory()->create(['surfaced_at' => now()->subDay()]);
        ($this->occurrences)($issue, 3, fn (int $i) => now()->subHours($i * 3));
        ($this->occurrences)($issue, 12, fn (int $i) => now()->subSeconds($i * 10));

        expect(app(DetectEscalationAction::class)->execute($issue, 'production'))->toBeTrue()
            ->and($issue->refresh()->isEscalating())->toBeTrue()
            ->and($issue->surfaced_at?->isToday())->toBeTrue();

        Event::assertDispatched(IssueEscalated::class, fn (IssueEscalated $e): bool => $e->burst === 12);
    });

    it('flags a burst that arrives at once, not only one spread over the throttle window', function () {
        $issue = Issue::factory()->create(['surfaced_at' => now()->subDay()]);
        $detect = app(DetectEscalationAction::class);
        $flagged = false;

        foreach (range(1, 12) as $i) {
            Occurrence::factory()->for($issue)->create(['project_id' => $issue->project_id, 'occurred_at' => now()]);
            $flagged = $detect->execute($issue->refresh(), 'production') || $flagged;
        }

        expect($flagged)->toBeTrue()->and($issue->refresh()->isEscalating())->toBeTrue();
    });

    it('does not flag an issue that always happens this often', function () {
        $issue = Issue::factory()->create();
        // Three every five minutes all day: twelve in five minutes is only four times that.
        DB::table('occurrences')->insert(array_map(fn (int $i): array => [
            'issue_id' => $issue->id, 'project_id' => $issue->project_id, 'environment' => 'production',
            'stacktrace' => '[]', 'context' => '[]', 'occurred_at' => now()->subMinutes(5)->subSeconds($i * 100)->format('Y-m-d H:i:s.v'),
        ], range(1, 850)));
        ($this->occurrences)($issue, 12, fn (int $i) => now()->subSeconds($i * 10));

        expect(app(DetectEscalationAction::class)->execute($issue, 'production'))->toBeFalse();
    });

    it('does not flag a small burst, an issue that is not open, or one already escalating', function () {
        $small = Issue::factory()->create();
        ($this->occurrences)($small, DetectEscalationAction::MIN - 1, fn (int $i) => now()->subSeconds($i * 10));

        $resolved = Issue::factory()->resolved()->create();
        ($this->occurrences)($resolved, 20, fn (int $i) => now()->subSeconds($i * 10));

        $escalating = Issue::factory()->create(['escalated_at' => now()->subMinutes(10)]);
        ($this->occurrences)($escalating, 20, fn (int $i) => now()->subSeconds($i * 10));

        expect(app(DetectEscalationAction::class)->execute($small, null))->toBeFalse()
            ->and(app(DetectEscalationAction::class)->execute($resolved, null))->toBeFalse()
            ->and(app(DetectEscalationAction::class)->execute($escalating, null))->toBeFalse();
        Event::assertNotDispatched(IssueEscalated::class);
    });

    it('counts a flood in the cache, not with a query per occurrence', function () {
        $issue = Issue::factory()->create(['surfaced_at' => now()->subDay()]);
        $detect = app(DetectEscalationAction::class);
        // Steady: three a minute all day, so 40 in five minutes is under five times that.
        DB::table('occurrences')->insert(array_map(fn (int $i): array => [
            'issue_id' => $issue->id, 'project_id' => $issue->project_id, 'environment' => 'production',
            'stacktrace' => '[]', 'context' => '[]', 'occurred_at' => now()->subSeconds(300 + $i * 20)->format('Y-m-d H:i:s.v'),
        ], range(1, 4200)));

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            $queries += str_contains($query->sql, 'occurrences') ? 1 : 0;
        });

        foreach (range(1, 40) as $_) {
            Occurrence::factory()->for($issue)->create(['project_id' => $issue->project_id, 'occurred_at' => now()]);
            expect($detect->execute($issue, 'production'))->toBeFalse();
        }

        // 40 inserts, one recount of the window and one count of the day before it.
        expect($queries)->toBe(42);
    });

    it('stops escalating an hour later', function () {
        $issue = Issue::factory()->create(['escalated_at' => now()->subMinutes(DetectEscalationAction::ACTIVE_MINUTES + 1)]);

        expect($issue->isEscalating())->toBeFalse();
    });
});

describe('the escalation alert', function () {
    it('says how many occurrences came in how short a time', function () {
        Http::fake(['discord.com/*' => Http::response(null, 204)]);
        $issue = Issue::factory()->create();
        $channel = NotificationChannel::factory()->for($issue->project->team)->create();
        AlertRule::factory()->for($issue->project)->create(['type' => AlertRuleType::Escalation])->channels()->attach($channel);

        app(SendIssueAlertsAction::class)->execute($issue, AlertRuleType::Escalation, 'production', 42);

        Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['description'], '42 occurrences in 5 minutes')
            && str_starts_with($request['embeds'][0]['title'], 'Escalating in'));
    });
});
