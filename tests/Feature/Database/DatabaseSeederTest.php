<?php

declare(strict_types=1);

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

covers(DatabaseSeeder::class);

describe('the local seed', function () {
    beforeEach(fn () => $this->seed(DatabaseSeeder::class));

    it('creates an admin who owns the Demo project, with keys the SDK workbench can rely on', function () {
        $admin = User::query()->where('email', DatabaseSeeder::EMAIL)->sole();
        $project = Project::query()->where('slug', 'demo')->sole();

        expect(Hash::check(DatabaseSeeder::PASSWORD, $admin->password))->toBeTrue()
            ->and($admin->currentTeam?->is($project->team))->toBeTrue()
            ->and($project->public_key)->toBe(DatabaseSeeder::PUBLIC_KEY)
            ->and($project->logChannels()->pluck('name')->all())->toBe(['default'])
            ->and($project->team->alertRules()->count())->toBe(count(AlertRuleType::cases()))
            ->and($project->alertRules()->sole()->type)->toBe(AlertRuleType::Escalation);

        $this->withToken(DatabaseSeeder::TOKEN)
            ->postJson('/api/ingest/heartbeat', ['host' => 'web-1'])
            ->assertOk();
    });

    it('lets the members see Demo and keeps the other team out', function () {
        $project = Project::query()->where('slug', 'demo')->sole();

        foreach (DatabaseSeeder::MEMBERS as $member) {
            expect(User::query()->where('email', $member['email'])->sole()->can('view', $project))->toBeTrue();
        }

        foreach (DatabaseSeeder::OUTSIDERS as $outsider) {
            expect(User::query()->where('email', $outsider['email'])->sole()->can('view', $project))->toBeFalse();
        }
    });

    it('creates the MCP agents with tokens that work, covering Demo', function () {
        foreach (DatabaseSeeder::AGENTS as $agent) {
            $token = McpToken::query()->where('token_hash', McpToken::hash($agent['token']))->sole();

            expect($token->name)->toBe($agent['name'])
                ->and($token->canWrite())->toBe($agent['write'])
                ->and($token->projects()->pluck('slug')->all())->toBe(['demo']);
        }
    });
});
