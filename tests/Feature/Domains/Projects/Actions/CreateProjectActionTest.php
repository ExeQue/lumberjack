<?php

declare(strict_types=1);

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Actions\CreateProjectAction;
use App\Domains\Projects\Support\ProjectKeys;
use App\Domains\Teams\Models\Team;

covers(CreateProjectAction::class);

describe('creating a project', function () {
    it('stores only the hash of the server token it returns', function () {
        ['project' => $project, 'token' => $token] = app(CreateProjectAction::class)->execute(Team::factory()->create(), 'Shop');

        expect($project->token_hash)->toBe(ProjectKeys::hash($token))
            ->and($project->token_last_four)->toBe(substr($token, -4))
            ->and($project->public_key)->toStartWith('pk_');
    });

    it('creates the default log channel and no alert rules of its own, so it uses the team\'s', function () {
        $team = Team::factory()->create();

        ['project' => $project] = app(CreateProjectAction::class)->execute($team, 'Shop');

        expect($project->logChannels()->pluck('name')->all())->toBe([LogChannel::DEFAULT])
            ->and($project->alertRules()->count())->toBe(0)
            ->and(AlertRule::query()->whereEffectiveFor($project)->pluck('team_id')->unique()->all())->toBe([$team->id])
            ->and(AlertRule::query()->whereEffectiveFor($project)->count())->toBe(count(AlertRuleType::cases()));
    });

    it('keeps slugs unique within the team', function () {
        $team = Team::factory()->create();

        app(CreateProjectAction::class)->execute($team, 'Shop');
        ['project' => $second] = app(CreateProjectAction::class)->execute($team, 'Shop');

        expect($second->slug)->toBe('shop-2');
    });
});
