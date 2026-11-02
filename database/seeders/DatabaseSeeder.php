<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development: the Local Team with the Demo project, three of its
 * members and three MCP agents, plus a second team whose people have no
 * access to Demo. Every password is "password". The Demo keys and the agent
 * tokens are fixed, so the SDK workbench and local MCP clients keep working
 * after a fresh seed.
 */
class DatabaseSeeder extends Seeder
{
    public const string EMAIL = 'admin@lumberjack';

    public const string PASSWORD = 'password';

    public const string TOKEN = 'lj_srv_local_demo_project_server_token_0000000';

    public const string PUBLIC_KEY = 'pk_local_demo_project';

    /** Members of the Local Team, who can see Demo. */
    public const array MEMBERS = [
        ['name' => 'Local Admin', 'email' => self::EMAIL, 'role' => TeamRole::Owner],
        ['name' => 'Sofie Admin', 'email' => 'sofie@lumberjack', 'role' => TeamRole::Admin],
        ['name' => 'Jonas Member', 'email' => 'jonas@lumberjack', 'role' => TeamRole::Member],
    ];

    /** People of another team, who cannot see Demo. */
    public const array OUTSIDERS = [
        ['name' => 'Eve Outsider', 'email' => 'eve@lumberjack', 'role' => TeamRole::Owner],
        ['name' => 'Noah Outsider', 'email' => 'noah@lumberjack', 'role' => TeamRole::Member],
    ];

    /** MCP agents on the Local Team, all covering Demo. */
    public const array AGENTS = [
        ['name' => 'triage-bot', 'token' => 'lj_mcp_local_triage_bot_token_00000000000', 'write' => true],
        ['name' => 'claude-code', 'token' => 'lj_mcp_local_claude_code_token_0000000000', 'write' => true],
        ['name' => 'readonly-reporter', 'token' => 'lj_mcp_local_readonly_reporter_token_0000', 'write' => false],
    ];

    public function run(): void
    {
        $team = Team::factory()->create(['name' => 'Local Team']);

        foreach (self::MEMBERS as $member) {
            User::factory()->inTeam($team, $member['role'])->create(['name' => $member['name'], 'email' => $member['email'], 'password' => self::PASSWORD]);
        }

        // The admin runs this Lumberjack: they see every team, the other one included.
        User::query()->where('email', self::EMAIL)->update(['is_admin' => true]);

        $project = Project::factory()->for($team)->withToken(self::TOKEN)->create([
            'name' => 'Demo',
            'slug' => 'demo',
            'public_key' => self::PUBLIC_KEY,
            'allowed_origins' => ['http://localhost:3000', 'https://lumberjack.test', 'https://laravel-lumberjack.test'],
        ]);

        AlertRule::factory()->for($project)->create();
        AlertRule::factory()->for($project)->regression()->create();
        AlertRule::factory()->for($project)->create(['type' => AlertRuleType::Escalation]);
        AlertRule::factory()->for($project)->create(['type' => AlertRuleType::Note, 'enabled' => false]);
        AlertRule::factory()->for($project)->create(['type' => AlertRuleType::StatusChange, 'enabled' => false]);

        foreach (self::AGENTS as $agent) {
            McpToken::factory()->for($team)->withToken($agent['token'])
                ->when(! $agent['write'])->readOnly()
                ->create(['name' => $agent['name'], 'user_id' => $team->members()->value('users.id')])
                ->projects()->attach($project);
        }

        $other = Team::factory()->create(['name' => 'Acme Agency']);

        foreach (self::OUTSIDERS as $outsider) {
            User::factory()->inTeam($other, $outsider['role'])->create(['name' => $outsider['name'], 'email' => $outsider['email'], 'password' => self::PASSWORD]);
        }

        Project::factory()->for($other)->create(['name' => 'Acme Shop', 'slug' => 'acme-shop']);

        $this->command?->info('Log in as '.implode(', ', array_column([...self::MEMBERS, ...self::OUTSIDERS], 'email')).' / '.self::PASSWORD);
        $this->command?->info("Project \"{$project->name}\" server token: ".self::TOKEN);
        $this->command?->info("Project \"{$project->name}\" public key: ".self::PUBLIC_KEY);

        foreach (self::AGENTS as $agent) {
            $this->command?->info("MCP agent {$agent['name']} (".($agent['write'] ? 'read/write' : 'read').'): '.$agent['token']);
        }
    }
}
