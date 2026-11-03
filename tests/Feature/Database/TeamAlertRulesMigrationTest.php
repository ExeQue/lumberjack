<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\CustomizeAlertRuleAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Support\Facades\DB;

// The migration is an anonymous class; this is the resolution it has to keep working.
covers(App\Domains\Alerts\Eloquent\Builders\AlertRuleBuilder::class);

describe('moving alert rules to the team', function () {
    it('lifts rules every project shares to the team and keeps the rest as overrides', function () {
        $migration = require database_path('migrations/2026_11_03_180000_add_team_alert_rules.php');

        $a = Team::factory()->create();
        $b = Team::factory()->create();
        [$a1, $a2] = Project::factory()->for($a)->count(2)->create()->all();
        [$b1, $b2] = Project::factory()->for($b)->count(2)->create()->all();
        $channel = NotificationChannel::factory()->for($a)->create();
        AlertRule::query()->whereForTeam($a)->whereType(AlertRuleType::NewIssue)->sole()->channels()->attach($channel);
        app(CustomizeAlertRuleAction::class)->execute($b1, AlertRuleType::NewIssue)->update(['environments' => ['production']]);

        // Back to rules per project only: every project has its own copy.
        $migration->down();

        $a2Rule = DB::table('alert_rules')->where('project_id', $a2->id)->where('type', 'new_issue')->value('id');
        $issue = Issue::factory()->for($a2)->create();
        DB::table('alert_sends')->insert(['alert_rule_id' => $a2Rule, 'issue_id' => $issue->id, 'notification_channel_id' => $channel->id, 'status' => 'sent', 'sent_at' => now()]);
        DB::table('alert_rules')->where('project_id', $b2->id)->where('type', 'note')->delete();

        expect(DB::table('alert_rules')->count())->toBe(4 * count(AlertRuleType::cases()) - 1);

        $migration->up();

        $teamA = AlertRule::query()->whereForTeam($a)->whereType(AlertRuleType::NewIssue)->sole();

        expect($a1->alertRules()->count())->toBe(0)
            ->and($a2->alertRules()->count())->toBe(0)
            ->and($teamA->channels()->pluck('notification_channels.id')->all())->toBe([$channel->id])
            ->and(DB::table('alert_sends')->value('alert_rule_id'))->toBe($teamA->id)
            // Team B differs on new issues, and its second project had no note rule.
            ->and($b1->alertRules()->pluck('type')->map->value->sort()->values()->all())->toBe(['new_issue', 'note'])
            ->and($b2->alertRules()->pluck('type')->map->value->sort()->values()->all())->toBe(['new_issue', 'note'])
            ->and($b1->alertRules()->where('type', 'new_issue')->value('environments'))->toBe(['production'])
            ->and($b2->alertRules()->where('type', 'note')->value('enabled'))->toBeFalse()
            ->and(AlertRule::query()->whereForTeam($b)->count())->toBe(count(AlertRuleType::cases()));
    });
});
